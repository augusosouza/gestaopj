<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$raiz = require __DIR__.'/raiz-augi.php';
if ($raiz === null) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    exit('A publicação do Augi precisa ser ajustada. Consulte o guia de instalação.');
}

if (is_file($maintenance = $raiz.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $raiz.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once $raiz.'/bootstrap/app.php';
$app->usePublicPath(__DIR__);
$app->handleRequest(Request::capture());
