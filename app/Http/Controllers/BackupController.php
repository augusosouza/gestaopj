<?php

namespace App\Http\Controllers;

use App\Models\Backup;
use App\Models\Evento;
use App\Services\Backup\ConfigBackup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/** Plataforma → Backups (somente administrador da plataforma, com 2FA). */
class BackupController extends Controller
{
    public function __construct(private ConfigBackup $cfg) {}

    public function index(): View
    {
        $c = $this->cfg->todos();
        $ultimo = Backup::where('status', 'concluido')->latest('id')->first();

        return view('plataforma.backups', [
            'c' => $c,
            'destinos' => ConfigBackup::DESTINOS + (app()->environment('local', 'testing') ? ['local' => 'Pasta local (só desenvolvimento)'] : []),
            'backups' => Backup::latest('id')->limit(40)->get(),
            'ultimo' => $ultimo,
            'atrasado' => $this->cfg->ativo() && (! $ultimo || $ultimo->iniciado_em->lt(now()->subHours(26))),
            'cron' => 'php '.base_path('artisan').' schedule:run',
        ]);
    }

    public function salvar(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'destino' => ['required', Rule::in(array_keys(ConfigBackup::DESTINOS + ['local' => 1]))],
            'bucket' => ['nullable', 'required_unless:destino,desligado,local', 'string', 'max:120'],
            'regiao' => ['nullable', 'string', 'max:40'],
            'endpoint' => ['nullable', 'required_if:destino,compativel', 'url:https', 'max:200'],
            'prefixo' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9._\\/-]*$/'],
            'chave' => ['nullable', 'string', 'max:200'],
            'segredo' => ['nullable', 'string', 'max:300'],
            'horarios' => ['required', 'string', 'max:100', 'regex:/^\\s*([01]\\d|2[0-3]):[0-5]\\d(\\s*,\\s*([01]\\d|2[0-3]):[0-5]\\d)*\\s*$/'],
            'dias_base' => ['required', 'integer', 'between:1,31'],
            'bases_mantidas' => ['required', 'integer', 'between:1,520'],
            'nova_senha' => ['nullable', 'boolean'],
        ], [
            'horarios.regex' => 'Use horários no formato HH:MM separados por vírgula (ex.: 06:00,13:00,20:00).',
            'bucket.required_unless' => 'Informe o bucket do destino.',
            'endpoint.required_if' => 'Para provedores compatíveis, informe o endpoint (https://…).',
        ]);
        if ($d['destino'] === 'local') {
            abort_unless(app()->environment('local', 'testing'), 422, 'Destino local só em desenvolvimento.');
        }
        if (! in_array($d['destino'], ['desligado', 'local'], true) && blank($d['chave'] ?? null) && blank($this->cfg->get('chave'))) {
            throw ValidationException::withMessages(['chave' => 'Informe a chave de acesso (Access Key / HMAC).']);
        }
        if (! in_array($d['destino'], ['desligado', 'local'], true) && blank($d['segredo'] ?? null) && blank($this->cfg->get('segredo'))) {
            throw ValidationException::withMessages(['segredo' => 'Informe a chave secreta.']);
        }

        $r = $this->cfg->salvar($d, $request->user());
        Evento::create(['user_id' => $request->user()->id, 'ip' => $request->ip(), 'acao' => 'backup.configurado',
            'alvo_type' => $request->user()->getMorphClass(), 'alvo_id' => $request->user()->id,
            'dados' => ['destino' => $d['destino'], 'nova_senha' => (bool) $r['senha_gerada']]]);

        $msg = 'Configuração de backup salva.';
        if ($r['base_forcada']) {
            $msg .= ' Nova senha de criptografia: o próximo backup será uma base completa. Os backups antigos continuam legíveis com a senha anterior.';
        }

        return redirect()->route('plataforma.backups')->with('ok', $msg)->with('senha_gerada', $r['senha_gerada']);
    }

    /** Grava, lê e apaga um arquivo de teste no destino. */
    public function testar(): RedirectResponse
    {
        try {
            $disco = $this->cfg->disco();
            $arq = $this->cfg->caminho('.teste-conexao-'.Str::random(8));
            $disco->put($arq, 'ok');
            $lido = $disco->get($arq);
            $disco->delete($arq);
            throw_unless($lido === 'ok', \RuntimeException::class, 'Conteúdo lido diferente do gravado.');
        } catch (\Throwable $e) {
            return back()->withErrors(['destino' => 'Falha ao acessar o destino: '.Str::limit($e->getMessage(), 300)]);
        }

        return back()->with('ok', 'Conexão com o destino funcionando: gravação, leitura e exclusão testadas.');
    }

    /** Agenda para o próximo minuto (o agendador executa): funciona em cPanel, onde a página não pode rodar por muito tempo. */
    public function executarAgora(Request $request): RedirectResponse
    {
        if (! $this->cfg->ativo()) {
            return back()->withErrors(['destino' => 'Configure e salve o destino antes de executar.']);
        }
        $this->cfg->gravar('solicitado', $request->boolean('base') ? 'base' : 'manual', $request->user());

        return back()->with('ok', 'Backup solicitado: começa em até 1 minuto pelo agendador. Atualize a página para ver o andamento.');
    }

    /** Folha de recuperação: tudo o que é preciso para restaurar após perda total do servidor. */
    public function folha(Request $request): Response
    {
        $request->validate(['senha_conta' => ['required', 'string']]);
        if (! Hash::check($request->input('senha_conta'), $request->user()->password)) {
            throw ValidationException::withMessages(['senha_conta' => 'Senha incorreta.']);
        }
        $c = $this->cfg->todos();
        Evento::create(['user_id' => $request->user()->id, 'ip' => $request->ip(), 'acao' => 'backup.folha_baixada',
            'alvo_type' => $request->user()->getMorphClass(), 'alvo_id' => $request->user()->id]);
        $txt = implode("\n", [
            'FOLHA DE RECUPERAÇÃO — '.config('app.name'), 'Gerada em '.now()->format('d/m/Y H:i').' por '.$request->user()->email, str_repeat('=', 60),
            'Guarde em local seguro e FORA do servidor (gerenciador de senhas + cópia offline).', '',
            'BACKUP_DESTINO='.$c['destino'], 'BACKUP_BUCKET='.$c['bucket'], 'BACKUP_REGIAO='.$c['regiao'], 'BACKUP_ENDPOINT='.$c['endpoint'],
            'BACKUP_PREFIXO='.$c['prefixo'], 'BACKUP_CHAVE='.$c['chave'], 'BACKUP_SEGREDO='.$c['segredo'], 'BACKUP_SENHA='.$c['senha'],
            'BACKUP_SENHAS_ANTERIORES='.str_replace("\n", ' | ', (string) $c['senhas_anteriores']), '',
            'Para restaurar num servidor novo:',
            '  1. Instale o sistema e configure o .env (banco de dados e APP_KEY nova).',
            '  2. Acrescente ao .env as linhas BACKUP_* acima.',
            '  3. php artisan migrate',
            '  4. php artisan backup:listar',
            '  5. php artisan backup:restaurar --confirmar   (ou informe o nome de um pacote específico)',
            '  6. Após restaurar, reconfigure o destino na tela Plataforma → Backups e apague as linhas BACKUP_* do .env.',
        ]);

        return response($txt, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="folha-recuperacao-backup.txt"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
