<?php

namespace App\Console\Commands;

use App\Services\ImportadorExtrato;
use Illuminate\Console\Command;

/**
 * Prepara um extrato OFX real para virar caso de teste, sem dados pessoais:
 *  - agência e conta zeradas; código do banco mantido (é público);
 *  - nos históricos (MEMO/NAME), mantém só palavras do vocabulário bancário
 *    (PIX, TED, TARIFA...) e troca o resto (nomes, CPFs, chaves) por "X";
 *  - FITID trocado por um hash (continua único);
 *  - datas, valores e saldo mantidos: são o que o teste confere.
 * Gera também o .esperado.json como RASCUNHO ("conferido": false): confira contra o
 * extrato do banco e mude para true — só então o arquivo entra nos testes.
 */
class AnonimizarOfx extends Command
{
    protected $signature = 'ofx:anonimizar {entrada : arquivo OFX original} {nome : nome do caso, ex.: itau-pj-2026-11}';

    protected $description = 'Anonimiza um extrato OFX real e cria o caso de teste em tests/fixtures/ofx.';

    private const VOCABULARIO = ['PIX', 'TED', 'DOC', 'TEF', 'TARIFA', 'PACOTE', 'SERVICOS', 'SERVIÇOS', 'ENVIADO', 'ENVIADA', 'RECEBIDO', 'RECEBIDA',
        'BOLETO', 'PAGTO', 'PAGAMENTO', 'PAG', 'SISPAG', 'TRANSF', 'TRANSFERENCIA', 'TRANSFERÊNCIA', 'SALDO', 'RENDIMENTO', 'RENDIMENTOS', 'APLICACAO',
        'APLICAÇÃO', 'RESGATE', 'IOF', 'JUROS', 'DEBITO', 'DÉBITO', 'CREDITO', 'CRÉDITO', 'AUTOMATICO', 'AUTOMÁTICO', 'CARTAO', 'CARTÃO', 'FATURA',
        'SAQUE', 'DEPOSITO', 'DEPÓSITO', 'ESTORNO', 'DEVOLUCAO', 'DEVOLUÇÃO', 'INT', 'QRCODE', 'QR', 'CODE', 'CONTA', 'MENSALIDADE', 'IMPOSTO', 'DARF', 'DAS', 'GPS', 'FGTS'];

    public function handle(ImportadorExtrato $imp): int
    {
        $c = @file_get_contents($this->argument('entrada'));
        if ($c === false) {
            $this->error('Arquivo não encontrado.');

            return self::FAILURE;
        }
        $c = mb_check_encoding($c, 'UTF-8') ? $c : mb_convert_encoding($c, 'UTF-8', 'Windows-1252');
        $c = preg_replace('/(<ACCTID>)[^<\r\n]+/i', '${1}00000-0', $c);
        $c = preg_replace('/(<BRANCHID>)[^<\r\n]+/i', '${1}0000', $c);
        $c = preg_replace_callback('/(<FITID>)([^<\r\n]+)/i', fn ($m) => $m[1].substr(hash('sha256', trim($m[2])), 0, 16), $c);
        $c = preg_replace_callback('/(<(?:MEMO|NAME)>)([^<\r\n]*)/i', function ($m) {
            $palavras = preg_split('/\s+/', trim($m[2]));

            return $m[1].implode(' ', array_map(fn ($p) => in_array(mb_strtoupper($p), self::VOCABULARIO, true) || preg_match('/^[\-\/&]$/', $p) ? $p : 'X', $palavras));
        }, $c);

        $base = base_path('tests/fixtures/ofx/'.preg_replace('/[^a-z0-9\-]/', '-', strtolower($this->argument('nome'))));
        file_put_contents($base.'.ofx', $c);

        // Rascunho do esperado, lido pelo importador atual — precisa ser CONFERIDO contra o extrato do banco.
        $r = new \ReflectionClass($imp);
        $linhas = $r->getMethod('ofx')->invoke($imp, $c);
        $data = fn ($tag) => $r->getMethod('dataOfx')->invoke($imp, $c, $tag);
        preg_match('/<LEDGERBAL>.*?<BALAMT>\s*([-+]?[\d.,]+)/si', $c, $s);
        file_put_contents($base.'.esperado.json', json_encode([
            'descricao' => 'Extrato real anonimizado — CONFERIR contra o extrato do banco e marcar "conferido": true',
            'conferido' => false,
            'periodo' => [$data('DTSTART'), $data('DTEND')],
            'saldo_banco' => isset($s[1]) ? (int) round((float) str_replace(',', '.', $s[1]) * 100) : null,
            'saldo_em' => $data('DTASOF') ?? $data('DTEND'),
            'linhas' => array_map(fn ($l) => [$l['data'], $l['valor'], $l['descricao']], $linhas),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");

        $this->info("Criado: {$base}.ofx e {$base}.esperado.json (".count($linhas).' lançamentos).');
        $this->warn('Abra o .ofx e confira que não sobrou nenhum nome, CPF/CNPJ ou chave Pix. Depois confira o .esperado.json e marque "conferido": true.');

        return self::SUCCESS;
    }
}
