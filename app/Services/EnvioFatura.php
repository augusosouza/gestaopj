<?php

namespace App\Services;

use App\Enums\StatusFatura;
use App\Models\Evento;
use App\Models\Fatura;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class EnvioFatura
{
    public const SOLICITADO = 'fatura.envio_solicitado';

    public const ACEITO = 'fatura.envio_aceito';

    public const FALHOU = 'fatura.envio_falhou';

    public const ROTULOS = [
        self::SOLICITADO => 'Envio de fatura solicitado',
        self::ACEITO => 'Fatura aceita pelo servidor SMTP',
        self::FALHOU => 'Falha no envio da fatura',
    ];

    public const MOTIVOS = [
        'destinatario' => 'O e-mail de cobrança está ausente, inválido ou mudou desde a abertura da página.',
        'cancelada' => 'A fatura está cancelada.',
        'smtp' => 'O SMTP precisa ser configurado para enviar faturas.',
        'transporte' => 'Falha na comunicação com o SMTP. Confira a configuração antes de tentar novamente.',
        'sem_confirmacao' => 'O transporte não confirmou a aceitação da mensagem.',
    ];

    public function enviar(Fatura $fatura, string $chave, ?string $destinatarioConfirmado): ?string
    {
        $tentativa = DB::transaction(function () use ($fatura, $chave) {
            $atual = Fatura::query()->lockForUpdate()->with('contrato.cliente', 'contrato.empresa')->findOrFail($fatura->id);
            $existe = Evento::query()->where('organizacao_id', $atual->organizacao_id)
                ->whereMorphedTo('alvo', $atual)->where('acao', self::SOLICITADO)->where('dados->chave', $chave)->exists();
            if ($existe) {
                return null;
            }

            Evento::registrar(self::SOLICITADO, $atual, ['chave' => $chave, 'cliente_id' => $atual->contrato->cliente_id]);

            return $atual;
        });
        if ($tentativa === null) {
            return 'duplicado';
        }

        $cliente = $tentativa->contrato->cliente;
        $destinatario = $cliente->email;
        $transporte = Mail::getDefaultDriver();
        $motivo = match (true) {
            $tentativa->status === StatusFatura::Cancelada => 'cancelada',
            ! filter_var($destinatario, FILTER_VALIDATE_EMAIL), $destinatario !== $destinatarioConfirmado => 'destinatario',
            ! in_array($transporte, ['smtp', 'smtp-painel'], true) => 'smtp',
            default => null,
        };

        if ($motivo === null) {
            $texto = implode("\n", [
                'Olá, '.$cliente->razao_social.'.',
                '',
                'Segue o resumo da sua fatura de '.$tentativa->competencia->format('m/Y').'.',
                'Empresa: '.$tentativa->contrato->empresa->razao_social,
                'Fatura: #'.$tentativa->id,
                'Valor: '.brl($tentativa->valor_centavos),
                'Vencimento: '.$tentativa->vencimento->format('d/m/Y'),
                '',
                'Em caso de dúvidas, entre em contato com a empresa responsável pela fatura.',
            ]);
            try {
                $mensagem = Mail::mailer($transporte)->raw($texto, fn (Message $mail) => $mail
                    ->to($destinatario)->subject('Fatura #'.$tentativa->id.' · Augi Gestão'));
                $motivo = $mensagem === null ? 'sem_confirmacao' : null;
            } catch (Throwable) {
                $motivo = 'transporte';
            }
        }

        Evento::registrar($motivo === null ? self::ACEITO : self::FALHOU, $tentativa, [
            'chave' => $chave, 'cliente_id' => $cliente->id, 'motivo' => $motivo,
        ]);

        return $motivo;
    }
}
