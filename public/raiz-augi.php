<?php

declare(strict_types=1);

// O caminho vem da configuração da hospedagem, nunca da URL ou de um formulário.
$ajuste = __DIR__.'/.augi-root.php';
$caminho = getenv('AUGI_ROOT') ?: (is_file($ajuste) ? require $ajuste : dirname(__DIR__));
$raiz = is_string($caminho) && str_starts_with($caminho, '/') ? realpath($caminho) : false;
$documento = $_SERVER['DOCUMENT_ROOT'] ?? '';
$documentRoot = $documento !== '' ? realpath($documento) : false;

if (! $raiz || ! $documentRoot || ! is_file($raiz.'/bootstrap/app.php') || ! is_file($raiz.'/artisan')) {
    return null;
}

if ($raiz === $documentRoot || (str_starts_with($documentRoot.'/', $raiz.'/') && $documentRoot !== realpath($raiz.'/public'))) {
    return null;
}

if (str_starts_with($raiz.'/', rtrim($documentRoot, '/').'/')) {
    $marca = $_SERVER['REDIRECT_AUGI_PUBLICACAO_HTACCESS'] ?? $_SERVER['AUGI_PUBLICACAO_HTACCESS'] ?? '';
    if ($marca !== '1' || realpath(__DIR__) !== realpath($raiz.'/public') || ! is_file($raiz.'/.htaccess')) {
        return null;
    }

    // O servidor executa public/index.php ou public/instalar.php internamente.
    // Laravel e o formulário devem gerar links com o endereço externo.
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    if (preg_match('~^(.+)/public/(index|instalar)\.php$~D', $script, $partes)) {
        $_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = $partes[1].'/'.$partes[2].'.php';
    }
}

return $raiz;
