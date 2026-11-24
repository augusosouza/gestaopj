<?php

declare(strict_types=1);

use App\Support\CompatibilidadeInstalacao;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__.'/app/Support/CompatibilidadeInstalacao.php';
$requisitos = CompatibilidadeInstalacao::verificar(__DIR__);
$pendencias = false;
foreach ($requisitos as [$rotulo, $ok, $detalhe]) {
    echo ($ok ? '[OK] ' : '[PENDENTE] ').$rotulo.' — '.$detalhe.PHP_EOL;
    $pendencias = $pendencias || ! $ok;
}

if ($pendencias) {
    exit(1);
}
if (! is_file(__DIR__.'/.env')) {
    echo 'Configuração inicial ausente. Ative o instalador Web por HTTPS conforme o README.'.PHP_EOL;
    exit(0);
}

try {
    require __DIR__.'/vendor/autoload.php';
    $app = require __DIR__.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    DB::connection()->getPdo();
    echo '[OK] Conexão com o banco configurado'.PHP_EOL;
    $chave = (string) config('app.key');
    $chave = str_starts_with($chave, 'base64:') ? base64_decode(substr($chave, 7), true) : $chave;
    $chaveValida = is_string($chave) && Encrypter::supported($chave, config('app.cipher'));
    $url = (string) config('app.url');
    $https = filter_var($url, FILTER_VALIDATE_URL) !== false && parse_url($url, PHP_URL_SCHEME) === 'https';
    $smtp = config('mail.default') === 'smtp' && filled(config('mail.mailers.smtp.host')) && filled(config('mail.from.address'));
    $instaladorBloqueado = ! is_file(storage_path('app/instalador.sha256'));
    $tabelas = Schema::hasTable('migrations') && Schema::hasTable('users') && Schema::hasTable('cliente_relacionamentos');
    foreach ([
        ['Ambiente de produção sem depuração', app()->isProduction() && ! config('app.debug')],
        ['URL configurada com HTTPS e cookie seguro', $https && config('session.secure')],
        ['Chave de criptografia compatível', $chaveValida],
        ['Tabelas principais da versão presentes', $tabelas],
        ['Arquivo de ativação removido', $instaladorBloqueado],
    ] as [$rotulo, $ok]) {
        echo ($ok ? '[OK] ' : '[PENDENTE] ').$rotulo.PHP_EOL;
        $pendencias = $pendencias || ! $ok;
    }
    echo ($smtp ? '[CONFIGURADO] SMTP. Faça o teste de entrega na Administração Augi.' : '[PENDENTE] SMTP para convites e recuperação de senha.').PHP_EOL;
    echo 'Confira cron, filas e backup em Administração Augi → Saúde do sistema. Inspecione migrations com php artisan migrate:status.'.PHP_EOL;
} catch (Throwable) {
    fwrite(STDERR, 'Não foi possível verificar a configuração ou conectar ao banco. Confira o ambiente e o log privado.'.PHP_EOL);
    exit(1);
}
exit($pendencias ? 1 : 0);
