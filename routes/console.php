<?php

use App\Enums\Papel;
use App\Enums\TipoOrganizacao;
use App\Models\Backup;
use App\Models\Organizacao;
use App\Models\User;
use App\Models\Vinculo;
use App\Services\Backup\Backups;
use App\Services\Backup\ConfigBackup;
use App\Services\Faturamento;
use App\Services\Financeiro;
use App\Services\LembretesBancarios;
use App\Support\Competencia;
use App\Support\Documento;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;
use Spatie\ScheduleMonitor\Models\MonitoredScheduledTaskLogItem;

Artisan::command('gestao:gerar-competencia {competencia? : AAAA-MM (padrão: mês atual)}', function (Faturamento $f) {
    $c = Competencia::de($this->argument('competencia'));
    $n = $f->gerarCompetencia($c);
    $this->info("Competência {$c->format('m/Y')}: {$n['faturas']} fatura(s) e {$n['cobrancas']} cobrança(s) criadas.");
})->purpose('Gera faturas a receber e cobranças a pagar da competência (idempotente).');

// Todo dia 1º, às 06:00, abre a competência do mês.
Schedule::command('gestao:gerar-competencia')->monthlyOn(1, '06:00');

Artisan::command('gestao:nova-organizacao {tipo : empresa|escritorio} {cnpj} {razao_social} {email_admin} {nome_admin}', function () {
    $tipo = TipoOrganizacao::from($this->argument('tipo'));
    $cnpj = Documento::limpar($this->argument('cnpj'));
    if (! Documento::cnpjValido($cnpj)) {
        return $this->error('CNPJ inválido.');
    }
    $org = Organizacao::firstOrCreate(['cnpj' => $cnpj], ['tipo' => $tipo, 'razao_social' => $this->argument('razao_social')]);
    $email = Str::lower($this->argument('email_admin'));
    $user = User::firstOrCreate(['email' => $email], ['name' => $this->argument('nome_admin'), 'password' => Str::random(40)]);
    Vinculo::create([
        'user_id' => $user->id, 'organizacao_id' => $org->id, 'inicio' => now()->toDateString(),
        'papel' => $tipo === TipoOrganizacao::Escritorio ? Papel::Revisor : Papel::Admin,
    ]);
    Password::sendResetLink(['email' => $email]);
    $this->info("{$org->razao_social} criada. Link para definir a senha enviado a {$email}.");
})->purpose('Cria empresa ou escritório com o primeiro administrador (convite por e-mail).');

Artisan::command('gestao:admin-plataforma {email} {--remover}', function () {
    $user = User::where('email', Str::lower($this->argument('email')))->first();
    if (! $user) {
        return $this->error('Usuário não encontrado.');
    }
    $user->forceFill(['admin_plataforma' => ! $this->option('remover')])->save();
    $this->info($this->option('remover') ? "{$user->email} deixou de ser administrador da plataforma." : "{$user->email} agora é administrador da plataforma (precisará ativar o 2FA).");
})->purpose('Concede ou remove o perfil de administrador da plataforma.');

Artisan::command('financeiro:sincronizar', function (Financeiro $f) {
    $n = $f->sincronizarLegado();
    $this->info("Títulos criados: {$n['titulos']} · baixas migradas: {$n['baixas']}. Pode rodar de novo sem duplicar.");
})->purpose('Cria títulos para faturas/cobranças sem título e migra recebimentos/pagamentos antigos (idempotente).');

// ---------------- Backup ----------------
// Em cPanel basta UM cron: * * * * * php /caminho/artisan schedule:run
// O verificador roda a cada minuto e dispara nos horários configurados ou quando pedido na tela.

Artisan::command('backup:agenda', function (ConfigBackup $cfg, Backups $b) {
    if (! $cfg->ativo()) {
        return;
    }
    $solicitado = $cfg->get('solicitado');
    $agora = now()->format('H:i');
    if (! $solicitado && ! in_array($agora, $cfg->horarios(), true)) {
        return;
    }
    $cfg->gravar('solicitado', null);
    $r = $b->executar($solicitado ? 'manual' : 'agenda', $solicitado === 'base');
    $this->info($r ? "Backup {$r->nome}: {$r->status}" : 'Já havia um backup em andamento.');
})->purpose('Executa o backup nos horários configurados (chamado a cada minuto pelo agendador).');

Artisan::command('backup:executar {--base : força backup base}', function (Backups $b) {
    $r = $b->executar('manual', (bool) $this->option('base'));
    if (! $r) {
        return $this->warn('Já há um backup em andamento.');
    }
    $r->status === 'concluido'
        ? $this->info("{$r->nome} ({$r->tipo}): {$r->tamanhoLegivel()}, {$r->arquivos_enviados} arquivo(s) enviados de {$r->arquivos_total}.")
        : $this->error("Falhou: {$r->erro}");
})->purpose('Executa um backup agora (base ou incremental, conforme a necessidade).');

Artisan::command('backup:verificar', function (ConfigBackup $cfg, Backups $b) {
    if (! $cfg->ativo() || ! Backup::where('status', 'concluido')->exists()) {
        return $this->warn('Nenhum backup concluído para verificar.');
    }
    $r = $b->verificar();
    $this->info("Último pacote {$r['nome']} baixado, conferido e aberto: {$r['arquivos']} arquivo(s) no manifesto.");
})->purpose('Baixa o último backup, confere hash e senha e abre o manifesto.');

Artisan::command('backup:listar', function (Backups $b) {
    foreach ($b->remotos() as $n) {
        $this->line($n);
    }
})->purpose('Lista os pacotes de backup existentes no destino.');

Artisan::command('backup:restaurar {pacote? : nome do pacote (padrão: o mais recente)} {--extrair= : só extrai para esta pasta, sem alterar o sistema} {--confirmar : confirma a substituição do banco e dos arquivos}', function (Backups $b) {
    $extrair = $this->option('extrair');
    if (! $extrair && ! $this->option('confirmar')) {
        return $this->error('A restauração SUBSTITUI todo o banco e os arquivos. Rode com --confirmar (ou use --extrair=pasta para só conferir).');
    }
    if (! $extrair) {
        $this->call('down');
    }
    try {
        $r = $b->restaurar($this->argument('pacote'), $extrair);
        $this->info("Restaurado de {$r['pacote']}: {$r['arquivos']} arquivo(s) de {$r['pacotes_usados']} pacote(s)".($extrair ? " em {$extrair}" : '').'.');
    } finally {
        if (! $extrair) {
            $this->call('up');
        }
    }
})->purpose('Restaura banco e arquivos a partir do destino de backup.');

Schedule::command('backup:agenda')->everyMinute()->withoutOverlapping(360);
Schedule::command('backup:verificar')->weeklyOn(0, '04:17');

Artisan::command('financeiro:lembretes', function (LembretesBancarios $l) {
    $this->info('Notificações criadas: '.$l->notificar());
})->purpose('Verifica extratos desatualizados e conciliações pendentes e notifica os responsáveis.');

Schedule::command('financeiro:lembretes')->hourly();

Artisan::command('exportacoes:limpar', function () {
    // Download que falhou no meio (conexão caiu) deixa o temporário: apaga os com mais de 1 hora.
    $n = 0;
    foreach (glob(storage_path('app/private/exportacoes/*')) ?: [] as $f) {
        if (is_file($f) && filemtime($f) < time() - 3600 && unlink($f)) {
            $n++;
        }
    }
    $this->info("Exportações temporárias apagadas: {$n}");
})->purpose('Apaga arquivos temporários de exportação com mais de 1 hora.');

Schedule::command('exportacoes:limpar')->hourly();

// Saúde e monitoramento das rotinas (Laravel Health + Schedule Monitor).
Schedule::command('health:schedule-check-heartbeat')->everyMinute();
Schedule::command('health:check')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::command('model:prune', ['--model' => [MonitoredScheduledTaskLogItem::class]])->daily();
