<?php

use Spatie\Health\Notifications\Notifiable;
use Spatie\Health\ResultStores\CacheHealthResultStore;

/*
 * Laravel Health — saúde da plataforma (Administração Augi → Saúde do sistema).
 * Resultado guardado no cache (sem tabela nova). Avisos vão para as notificações
 * internas dos administradores da plataforma (App\Listeners\AvisarFalhaDeSaude),
 * não por e-mail: o e-mail ainda não está configurado.
 */
return [
    'result_stores' => [
        CacheHealthResultStore::class => [
            'store' => env('HEALTH_CACHE_STORE', env('CACHE_STORE', 'database')),
        ],
    ],
    'notifications' => [
        'enabled' => false,
        'notifications' => [],
        'notifiable' => Notifiable::class,
        'throttle_notifications_for_minutes' => 60,
        'throttle_notifications_key' => 'health:latestNotificationSentAt:',
        'only_on_failure' => true,
        'mail' => ['to' => '', 'from' => ['address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'), 'name' => env('MAIL_FROM_NAME', 'Augi')]],
        'slack' => ['webhook_url' => '', 'channel' => null, 'username' => null, 'icon' => null],
        'teams' => ['webhook_url' => ''],
    ],
    'oh_dear_endpoint' => ['enabled' => false, 'always_send_fresh_results' => true, 'secret' => null, 'url' => '/oh-dear-health-check-results'],
    'horizon' => ['heartbeat_url' => null],
    'schedule' => ['heartbeat_url' => null],
    'theme' => 'light',
    'silence_health_queue_job' => true,
    'json_results_failure_status' => 200,
    'secret_token' => null,
    'treat_skipped_as_failure' => false,
];
