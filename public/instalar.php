<?php

declare(strict_types=1);

use App\Enums\Papel;
use App\Enums\TipoOrganizacao;
use App\Models\Organizacao;
use App\Models\User;
use App\Models\Vinculo;
use App\Support\Documento;
use Dotenv\Dotenv;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

ini_set('display_errors', '0');
header('Cache-Control: no-store');
header('Content-Type: text/html; charset=UTF-8');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');

$root = require __DIR__.'/raiz-augi.php';
$script = $_SERVER['SCRIPT_NAME'] ?? '';
if (! preg_match('~^(?:/[A-Za-z0-9_-][A-Za-z0-9_.-]*)*/instalar\.php$~D', $script)) {
    http_response_code(400);
    exit('Não foi possível identificar o endereço do instalador.');
}
$base = substr($script, 0, -strlen('/instalar.php'));
if ($root === null) {
    http_response_code(503);
    exit('Publicação não configurada. Use /instalar.php na URL do Augi e confira o .htaccess da pasta do projeto ou a separação dos arquivos públicos descrita no README.');
}
$tokenFile = $root.'/storage/app/instalador.sha256';
$envFile = $root.'/.env';
$staged = $root.'/.env.instalacao';
$tokenHash = is_file($tokenFile) ? trim((string) file_get_contents($tokenFile)) : '';

if (! preg_match('/^[a-f0-9]{64}$/D', $tokenHash) || file_exists($envFile)) {
    http_response_code(404);
    exit;
}

$fields = [
    'codigo' => ['Código de ativação', 'password', 64],
    'url' => ['URL HTTPS do sistema', 'url', 255],
    'host' => ['Servidor do banco', 'text', 255],
    'porta' => ['Porta do banco', 'number', 5],
    'banco' => ['Nome do banco', 'text', 64],
    'usuario' => ['Usuário do banco', 'text', 128],
    'senha_banco' => ['Senha do banco', 'password', 255],
    'cnpj' => ['CNPJ da primeira empresa', 'text', 24],
    'empresa' => ['Razão social', 'text', 255],
    'nome' => ['Nome do administrador', 'text', 255],
    'email' => ['E-mail do administrador', 'email', 254],
    'senha' => ['Senha do administrador (12 caracteres ou mais)', 'password', 72],
];
$https = ! empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off';
$host = $_SERVER['HTTP_HOST'] ?? '';
$urlDetectada = 'https://'.$host.$base;
$values = ['host' => 'localhost', 'porta' => '3306'];
if (preg_match('~^(?:[A-Za-z0-9.-]+|\[[0-9A-Fa-f:]+\])(?::[0-9]{1,5})?$~D', $host)
    && filter_var($urlDetectada, FILTER_VALIDATE_URL)) {
    $values['url'] = $urlDetectada;
}
$ok = false;
$message = '';
$extensions = ['ctype', 'curl', 'dom', 'fileinfo', 'filter', 'hash', 'iconv', 'intl', 'json', 'libxml', 'mbstring', 'openssl', 'pcre', 'pdo', 'pdo_mysql', 'session', 'simplexml', 'sodium', 'tokenizer', 'xmlreader', 'xmlwriter', 'zip'];
$missing = array_values(array_filter($extensions, static fn ($name) => ! extension_loaded($name)));
$gravaveis = [$root, $root.'/storage/app', $root.'/bootstrap/cache', $root.'/storage/logs', $root.'/storage/framework/views'];
$requisitos = [
    ['PHP 8.3 ou superior', PHP_VERSION_ID >= 80300, PHP_VERSION],
    ['Extensões PHP', $missing === [], $missing ? 'Ausentes: '.implode(', ', $missing) : 'Extensões obrigatórias disponíveis'],
    ['Conexão HTTPS', $https, $https ? 'Ativa' : 'Abra o instalador por HTTPS'],
    ['Dependências PHP', is_file($root.'/vendor/autoload.php'), 'vendor/autoload.php'],
    ['Permissões de escrita', count(array_filter($gravaveis, 'is_writable')) === count($gravaveis), 'Raiz privada, storage e bootstrap/cache'],
];
$compatibilidadeOk = ! in_array(false, array_column($requisitos, 1), true);

if ($compatibilidadeOk && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $values = [];
    foreach ($fields as $key => [, $type, $max]) {
        $value = $_POST[$key] ?? '';
        $values[$key] = is_string($value) && strlen($value) <= $max && ! preg_match('/[\x00-\x1f\x7f]/', $value)
            ? ($type === 'password' ? $value : trim($value))
            : '';
    }

    if (! hash_equals($tokenHash, hash('sha256', $values['codigo']))) {
        http_response_code(403);
        $message = 'Código de ativação inválido.';
    } else {
        require $root.'/vendor/autoload.php';
        $values['url'] = rtrim($values['url'], '/');
        $values['cnpj'] = Documento::limpar($values['cnpj']);
        $values['email'] = Str::lower($values['email']);
        $url = parse_url($values['url']);
        $port = filter_var($values['porta'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);

        if (in_array('', $values, true) || ! $url || ($url['scheme'] ?? '') !== 'https'
            || ! filter_var($values['url'], FILTER_VALIDATE_URL) || array_intersect(['user', 'pass', 'query', 'fragment'], array_keys($url)) || ($url['path'] ?? '') !== $base
            || ! preg_match('/^[a-z0-9_.-]+$/iD', $values['host']) || ! preg_match('/^[a-z0-9_-]+$/iD', $values['banco'])
            || ! $port || ! Documento::cnpjValido($values['cnpj']) || ! filter_var($values['email'], FILTER_VALIDATE_EMAIL)
            || mb_strlen($values['senha']) < 12) {
            $message = 'Confira os campos. A URL HTTPS deve usar o mesmo caminho em que esta tela foi aberta.';
        } else {
            $lock = fopen($root.'/storage/app/instalacao.lock', 'c');
            if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
                $message = 'Já existe uma instalação em andamento nesta pasta. Aguarde e tente novamente.';
            } elseif (file_exists($envFile) || ! is_file($tokenFile)) {
                http_response_code(404);
                exit;
            } else {
                $safeError = 'Não foi possível conectar ao banco. Confira os dados e os privilégios do usuário.';
                try {
                    $pdo = new PDO("mysql:host={$values['host']};port={$port};dbname={$values['banco']};charset=utf8mb4",
                        $values['usuario'], $values['senha_banco'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                    $safeError = 'O banco precisa ser MariaDB 10.6+ ou MySQL 8.0+.';
                    $versao = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
                    $maria = stripos($versao, 'MariaDB') !== false;
                    $versao = $maria ? preg_replace('/^5\.5\.5-/', '', $versao) : $versao;
                    if (! preg_match('/^([0-9]+\.[0-9]+(?:\.[0-9]+)?)/', $versao, $partes)
                        || version_compare($partes[1], $maria ? '10.6' : '8.0', '<')) {
                        throw new RuntimeException;
                    }
                    $statement = $pdo->prepare('SELECT GET_LOCK(?, 0)');
                    $statement->execute([hash('sha256', 'augi-instalar:'.$values['banco'])]);
                    if ((int) $statement->fetchColumn() !== 1) {
                        $safeError = 'Já existe uma instalação usando esse banco. Aguarde e tente novamente.';
                        throw new RuntimeException;
                    }
                    $statement->closeCursor();

                    $settings = [
                        'APP_NAME' => 'Augi Gestão',
                        'APP_ENV' => 'production',
                        'APP_DEBUG' => 'false',
                        'APP_URL' => $values['url'],
                        'APP_TIMEZONE' => 'America/Sao_Paulo',
                        'APP_LOCALE' => 'pt_BR',
                        'SESSION_SECURE_COOKIE' => 'true',
                        'SESSION_PATH' => $base ?: '/',
                        'DB_CONNECTION' => 'mysql',
                        'DB_HOST' => $values['host'],
                        'DB_PORT' => (string) $port,
                        'DB_DATABASE' => $values['banco'],
                        'DB_USERNAME' => $values['usuario'],
                        'DB_PASSWORD' => $values['senha_banco'],
                        'MAIL_MAILER' => 'array',
                    ];
                    $fingerprint = hash_hmac('sha256', serialize($values), $values['codigo']);
                    $resume = is_file($staged);
                    if ($resume) {
                        $safeError = 'Para retomar a instalação, informe os mesmos dados usados na primeira tentativa.';
                        $previous = Dotenv::parse((string) file_get_contents($staged));
                        if (! hash_equals($previous['AUGI_INSTALL_FINGERPRINT'] ?? '', $fingerprint)) {
                            throw new RuntimeException;
                        }
                        $settings['APP_KEY'] = $previous['APP_KEY'];
                    } else {
                        $safeError = 'Use um banco dedicado, sem tabelas.';
                        if ($pdo->query('SHOW FULL TABLES')->fetchColumn() !== false) {
                            throw new RuntimeException;
                        }
                        $settings['APP_KEY'] = 'base64:'.base64_encode(random_bytes(32));
                        $safeError = 'Não foi possível salvar a configuração inicial na raiz do projeto.';
                        saveEnvironment($staged, $settings + ['AUGI_INSTALL_FINGERPRINT' => $fingerprint]);
                    }

                    $safeError = 'Não foi possível preparar o banco. Confira os privilégios e o log PHP da hospedagem. Se houve falha no meio de uma migração, use um novo banco vazio seguindo o README.';
                    $app = require $root.'/bootstrap/app.php';
                    $app->usePublicPath(__DIR__);
                    $app->loadEnvironmentFrom(basename($staged));
                    if ($app->configurationIsCached()) {
                        $safeError = 'Remova o cache de configuração desta instalação nova e tente novamente.';
                        throw new RuntimeException;
                    }
                    $kernel = $app->make(Kernel::class);
                    $kernel->bootstrap();
                    $connection = DB::connection();
                    if ($connection->getDriverName() !== 'mysql' || $connection->getDatabaseName() !== $values['banco']
                        || $connection->getConfig('host') !== $values['host'] || (int) $connection->getConfig('port') !== $port
                        || $connection->getConfig('username') !== $values['usuario'] || $connection->getConfig('password') !== $values['senha_banco']
                        || $connection->getConfig('url') || $connection->getConfig('unix_socket') || $connection->getTablePrefix()) {
                        $safeError = 'A configuração do servidor substituiu os dados do banco informados no instalador.';
                        throw new RuntimeException;
                    }
                    $connection->setPdo($pdo)->setReadPdo($pdo);
                    if ($kernel->call('migrate', ['--force' => true]) !== 0) {
                        throw new RuntimeException('Migration command failed');
                    }

                    $safeError = 'Não foi possível preparar o administrador ou publicar a configuração. Confira o log PHP e tente novamente com os mesmos dados.';
                    DB::transaction(static function () use ($values, $resume): void {
                        if (User::exists() || Organizacao::exists() || Vinculo::exists()) {
                            $user = User::where('email', $values['email'])->first();
                            $org = Organizacao::where('cnpj', $values['cnpj'])->first();
                            if (! $resume || ! $user?->admin_plataforma || ! $org || ! password_verify($values['senha'], $user->password)
                                || User::count() !== 1 || Organizacao::count() !== 1 || Vinculo::count() !== 1
                                || ! $user->vinculos()->where('organizacao_id', $org->id)->where('papel', Papel::Admin->value)->exists()) {
                                throw new RuntimeException('Database already initialized');
                            }

                            return;
                        }

                        $org = Organizacao::create(['tipo' => TipoOrganizacao::Empresa, 'cnpj' => $values['cnpj'], 'razao_social' => $values['empresa']]);
                        $user = new User(['name' => $values['nome'], 'email' => $values['email'], 'password' => $values['senha']]);
                        $user->forceFill(['admin_plataforma' => true])->save();
                        $user->vinculos()->create(['organizacao_id' => $org->id, 'papel' => Papel::Admin, 'inicio' => now()->toDateString()]);
                    });
                    if ($kernel->call('schedule-monitor:sync') !== 0) {
                        throw new RuntimeException('Schedule Monitor sync failed');
                    }
                    saveEnvironment($envFile, $settings);
                    @unlink($staged);
                    @unlink($tokenFile);
                    $ok = true;
                } catch (Throwable $error) {
                    error_log('Augi instalador: '.get_class($error).' em '.basename($error->getFile()).':'.$error->getLine());
                    $message = $safeError;
                } finally {
                    unset($statement, $pdo);
                    fclose($lock);
                }
            }
        }
    }
}

function saveEnvironment(string $path, array $settings): void
{
    $content = '';
    foreach ($settings as $key => $value) {
        $content .= $key.'="'.addcslashes($value, '\\"$').'"'."\n";
    }
    if (Dotenv::parse($content) !== $settings) {
        throw new RuntimeException('Environment value could not be encoded');
    }
    $temp = tempnam(dirname($path), '.augi-env-');
    if ($temp === false) {
        throw new RuntimeException('Could not create environment file');
    }
    try {
        if (! chmod($temp, 0600) || file_put_contents($temp, $content) !== strlen($content) || ! rename($temp, $path)) {
            throw new RuntimeException('Could not publish environment file');
        }
    } finally {
        if (is_file($temp)) {
            unlink($temp);
        }
    }
}

function escaped(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Instalação do Augi Gestão</title>
    <link rel="stylesheet" href="<?= escaped($base) ?>/css/instalador.css">
</head>
<body>
<main>
    <h1>Instalação do Augi Gestão</h1>
    <?php if (! $ok) { ?>
        <h2>Compatibilidade da hospedagem</h2>
        <ul class="requisitos">
            <?php foreach ($requisitos as [$rotulo, $passou, $detalhe]) { ?>
                <li><strong><?= escaped($rotulo) ?></strong> · <?= $passou ? 'OK' : 'Pendente' ?><br><?= escaped($detalhe) ?></li>
            <?php } ?>
            <li><strong>Banco de dados</strong> · Verificado após informar os dados (MariaDB 10.6+ ou MySQL 8.0+)</li>
        </ul>
    <?php } ?>
    <?php if ($ok) { ?>
        <p role="status">A primeira empresa e o administrador foram criados.</p>
        <p><a href="<?= escaped($base) ?>/login">Entrar e configurar a autenticação em dois fatores</a></p>
        <h2>Agendador e e-mail</h2>
        <p>No agendador do servidor, use o caminho do PHP CLI da mesma versão que atende o site. A linha abaixo é um exemplo para o cron do Linux.</p>
        <pre><?= escaped('* * * * * /usr/local/bin/php '.escapeshellarg($root.'/artisan').' schedule:run') ?></pre>
        <p>Confira a execução em Administração Augi → Saúde do sistema. Configure o SMTP no arquivo .env conforme o README para ativar convites e recuperação de senha.</p>
    <?php } else { ?>
        <?php if ($message) { ?><p role="alert"><?= escaped($message) ?></p><?php } ?>
        <?php if ($compatibilidadeOk) { ?>
            <p>Use um banco vazio criado no cPanel e o código que acompanha o pacote.</p>
            <form method="post" action="<?= escaped($script) ?>" autocomplete="off">
                <?php foreach ($fields as $key => [$label, $type, $max]) { ?>
                    <label for="<?= $key ?>"><?= escaped($label) ?></label>
                    <input id="<?= $key ?>" name="<?= $key ?>" type="<?= $type ?>" required
                        <?= $type === 'number' ? 'min="1" max="65535"' : 'maxlength="'.$max.'"' ?>
                        <?= $key === 'senha' ? 'minlength="12" autocomplete="new-password"' : '' ?>
                        value="<?= $type === 'password' ? '' : escaped($values[$key] ?? '') ?>">
                <?php } ?>
                <button type="submit">Instalar Augi Gestão</button>
            </form>
        <?php } ?>
    <?php } ?>
</main>
</body>
</html>
