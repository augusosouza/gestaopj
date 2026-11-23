<?php

namespace App\Listeners;

use App\Models\Notificacao;
use App\Models\User;
use Spatie\Health\Enums\Status;
use Spatie\Health\Events\CheckEndedEvent;

/** Falha numa verificação de saúde vira notificação interna para os administradores da plataforma (uma por verificação por dia). */
class AvisarFalhaDeSaude
{
    public function handle(CheckEndedEvent $e): void
    {
        if (! in_array($e->result->status, [Status::failed(), Status::crashed()], false)) {
            return;
        }
        try {
            foreach (User::where('admin_plataforma', true)->pluck('id') as $id) {
                Notificacao::avisar($id, null, 'saude', 'saude:'.$e->check->getName().':'.now()->format('Y-m-d'),
                    'Saúde do sistema: '.$e->check->getLabel(), $e->result->getNotificationMessage() ?: 'Verificação falhou.', route('plataforma.saude'));
            }
        } catch (\Throwable $erro) {
            report($erro); // o aviso nunca pode derrubar a própria verificação de saúde
        }
    }
}
