<?php

declare(strict_types=1);

namespace App\Support;

final class CompatibilidadeInstalacao
{
    public static function verificar(string $raiz): array
    {
        $extensoes = ['ctype', 'curl', 'dom', 'fileinfo', 'filter', 'hash', 'iconv', 'intl', 'json', 'libxml',
            'mbstring', 'openssl', 'pcre', 'pdo', 'pdo_mysql', 'session', 'simplexml', 'sodium', 'tokenizer', 'xmlreader', 'xmlwriter', 'zip'];
        $ausentes = array_values(array_filter($extensoes, static fn ($nome) => ! extension_loaded($nome)));
        $gravaveis = ['storage/app', 'bootstrap/cache', 'storage/logs', 'storage/framework/cache',
            'storage/framework/sessions', 'storage/framework/views'];
        if (! is_file($raiz.'/.env')) {
            $gravaveis[] = '.';
        }
        $semEscrita = array_values(array_filter($gravaveis, static fn ($pasta) => ! is_writable($raiz.'/'.$pasta)));
        $publicos = ['index.php', 'instalar.php', 'manifest.webmanifest', 'service-worker.js', 'pwa/offline.html'];
        $faltantes = array_values(array_filter($publicos, static fn ($arquivo) => ! is_readable($raiz.'/public/'.$arquivo)));

        return [
            ['PHP 8.3 ou superior', PHP_VERSION_ID >= 80300 && PHP_VERSION_ID < 90000, PHP_VERSION],
            ['Extensões PHP', $ausentes === [], $ausentes ? 'Ausentes: '.implode(', ', $ausentes) : 'Extensões obrigatórias disponíveis'],
            ['Dependências PHP', is_readable($raiz.'/vendor/autoload.php'), 'vendor/autoload.php'],
            ['Permissões de escrita', $semEscrita === [], $semEscrita ? 'Confira: '.implode(', ', $semEscrita) : 'Diretórios necessários graváveis'],
            ['Arquivos públicos do pacote', $faltantes === [], $faltantes ? 'Ausentes: '.implode(', ', $faltantes) : 'Pontos de entrada e arquivos do PWA encontrados'],
        ];
    }
}
