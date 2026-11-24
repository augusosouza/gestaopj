<?php

namespace App\Support;

use Illuminate\Mail\MailManager;

final class GerenciadorCorreio extends MailManager
{
    private const PAINEL = 'smtp-painel';

    public function getDefaultDriver()
    {
        return $this->app->make(CorreioPlataforma::class)->transporte() ? self::PAINEL : parent::getDefaultDriver();
    }

    protected function getConfig($name)
    {
        return $name === self::PAINEL
            ? $this->app->make(CorreioPlataforma::class)->transporte()
            : parent::getConfig($name);
    }

    protected function get($name)
    {
        return $name === self::PAINEL ? $this->resolve($name) : parent::get($name);
    }
}
