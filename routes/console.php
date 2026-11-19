<?php

use App\Enums\Papel;
use App\Enums\TipoOrganizacao;
use App\Models\Organizacao;
use App\Models\User;
use App\Models\Vinculo;
use App\Services\Faturamento;
use App\Support\Competencia;
use App\Support\Documento;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

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
