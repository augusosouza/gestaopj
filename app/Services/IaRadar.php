<?php

namespace App\Services;

use App\Models\Evento;
use App\Models\Organizacao;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

final class IaRadar
{
    public const MODELO = 'gpt-4.1-mini';

    public const ACOES = [
        'volume' => 'Compare o volume bruto e as modalidades antes de atribuir a diferença a uma mudança de tarifa.',
        'extrato' => 'Abra os lançamentos de tarifa e confira as cobranças correspondentes no extrato.',
        'contrato' => 'Confira a tabela contratada do banco e solicite esclarecimentos sobre os itens divergentes.',
        'amostra' => 'Reúna mais recebimentos tarifados nos dois períodos antes de estimar uma economia ajustada.',
        'acrescimos' => 'Revise os acréscimos lançados e identifique quais dependem de atraso ou renegociação.',
    ];

    public function orientar(Organizacao $empresa, array $radar): ?array
    {
        $credencial = DB::transaction(function () use ($empresa) {
            $atual = Organizacao::query()->lockForUpdate()->findOrFail($empresa->id);
            if (! $atual->ia_radar_ativa || ! $atual->ia_radar_chave || ! $atual->sugestoes_ativas) {
                return null;
            }
            $uso = Evento::query()->where('organizacao_id', $atual->id)->where('acao', 'ia.radar_solicitada')
                ->where('created_at', '>=', now()->startOfMonth())->count();
            if ($uso >= $atual->ia_radar_limite) {
                return null;
            }
            Evento::registrar('ia.radar_solicitada', $atual, ['modelo' => self::MODELO]);

            return $atual->ia_radar_chave;
        });
        if ($credencial === null) {
            return null;
        }

        $permitidas = ['volume', 'extrato', 'contrato'];
        if (! $radar['grupos']) {
            $permitidas[] = 'amostra';
        }
        if ($radar['atual']['acrescimos'] > 0) {
            $permitidas[] = 'acrescimos';
        }

        try {
            $resposta = Http::withToken($credencial)->acceptJson()->connectTimeout(3)->timeout(10)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => self::MODELO, 'max_completion_tokens' => 120,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => 'Priorize até três ações do catálogo para revisão financeira humana. Responda somente JSON com a chave acoes e uma lista dos códigos permitidos.'],
                        ['role' => 'user', 'content' => json_encode([
                            'acoes_permitidas' => array_intersect_key(self::ACOES, array_flip($permitidas)),
                            'comparacao_disponivel' => $radar['grupos'] > 0,
                            'tarifas_totais_subiram' => $radar['atual']['tarifas'] > $radar['base']['tarifas'],
                            'custo_ajustado_caiu' => ($radar['diferenca'] ?? 0) > 0,
                        ], JSON_THROW_ON_ERROR)],
                    ],
                ]);
            $texto = $resposta->json('choices.0.message.content');
            $dados = is_string($texto) ? json_decode($texto, true) : null;
            $acoes = $dados['acoes'] ?? null;
            if ($resposta->failed() || ! is_array($acoes) || ! array_is_list($acoes) || count($acoes) < 1 || count($acoes) > 3) {
                return null;
            }
            foreach ($acoes as $acao) {
                if (! is_string($acao) || ! in_array($acao, $permitidas, true)) {
                    return null;
                }
            }
            Evento::registrar('ia.radar_concluida', $empresa, ['modelo' => self::MODELO,
                'tokens' => max(0, (int) $resposta->json('usage.total_tokens', 0))]);

            return array_map(fn ($acao) => self::ACOES[$acao], array_values(array_unique($acoes)));
        } catch (\Throwable) {
            return null;
        }
    }
}
