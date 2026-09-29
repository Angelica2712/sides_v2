<?php

namespace App\Console\Commands;

use App\Services\Webhooks\WebhooksService;
use Illuminate\Console\Command;

/** Reintenta los avisos por webhook que la aplicación externa no recibió al primer intento. */
class EnviarWebhooks extends Command
{
    protected $signature = 'sides:enviar-webhooks {--limite=100 : Máximo de avisos por corrida}';

    protected $description = 'Envía los avisos por webhook pendientes cuyo reintento ya toca';

    public function handle(WebhooksService $webhooks): int
    {
        $entregados = $webhooks->enviarPendientes((int) $this->option('limite'));
        $this->info("{$entregados} avisos entregados.");

        return self::SUCCESS;
    }
}
