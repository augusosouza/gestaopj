<?php

use App\Services\Faturamento;
use App\Support\Competencia;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('gestao:gerar-competencia {competencia? : AAAA-MM (padrão: mês atual)}', function (Faturamento $f) {
    $c = Competencia::de($this->argument('competencia'));
    $n = $f->gerarCompetencia($c);
    $this->info("Competência {$c->format('m/Y')}: {$n['faturas']} fatura(s) e {$n['cobrancas']} cobrança(s) criadas.");
})->purpose('Gera faturas a receber e cobranças a pagar da competência (idempotente).');

// Todo dia 1º, às 06:00, abre a competência do mês.
Schedule::command('gestao:gerar-competencia')->monthlyOn(1, '06:00');
