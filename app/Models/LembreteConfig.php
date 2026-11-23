<?php

namespace App\Models;

use App\Models\Concerns\DaOrganizacao;
use Illuminate\Database\Eloquent\Model;

/** Quando avisar sobre vencimentos: padrão do usuário por tipo, ou ajuste de uma obrigação (titulo_id). */
class LembreteConfig extends Model
{
    use DaOrganizacao;

    public const DIAS = [15 => '15 dias antes', 10 => '10 dias antes', 5 => '5 dias antes', 1 => '1 dia antes', 0 => 'No dia do vencimento'];

    public const TIPOS = ['pagar' => 'Contas a pagar', 'receber' => 'Contas a receber', 'cartao' => 'Faturas de cartão'];

    public const CANAIS = ['popup' => 'Notificação do Google Agenda', 'email' => 'E-mail do Google Agenda'];

    /** Padrão de quem nunca configurou: 5 e 1 dia antes e no dia, às 9h. */
    public const PADRAO = ['ativo' => true, 'dias' => [5, 1, 0], 'hora' => '09:00', 'canais_google' => ['popup']];

    protected $fillable = ['organizacao_id', 'user_id', 'tipo', 'titulo_id', 'ativo', 'dias', 'hora', 'canais_google'];

    protected function casts(): array
    {
        return ['ativo' => 'boolean', 'dias' => 'array', 'canais_google' => 'array'];
    }
}
