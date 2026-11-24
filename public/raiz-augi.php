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

if ($raiz === $documentRoot || str_starts_with($raiz.'/', rtrim($documentRoot, '/').'/')
    || (str_starts_with($documentRoot.'/', $raiz.'/') && $documentRoot !== realpath($raiz.'/public'))) {
    return null;
}

return $raiz;
