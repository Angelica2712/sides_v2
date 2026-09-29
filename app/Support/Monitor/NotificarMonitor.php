<?php

namespace App\Support\Monitor;

use App\Events\MonitorActualizado;
use App\Services\Webhooks\WebhooksService;
use Illuminate\Support\Facades\Log;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Único punto por el que SIDES avisa que un pedido cambió: al monitor (WebSocket) y a las
 * aplicaciones externas suscritas por webhook (App\Services\Webhooks\WebhooksService). Se
 * llama desde los controladores, después de que la transacción del servicio ya cerró: si se
 * llamara dentro, el monitor podría pedir el contenido nuevo antes de que el cambio esté
 * visible para otras conexiones.
 *
 * Nunca deja caer la acción del operario: si Reverb está caído, el pedido igual se tomó o se
 * terminó, y el monitor se pone al día cuando el socket vuelve (resources/js/monitor.js
 * recarga al reconectar). Los webhooks se guardan en la petición y se envían después de
 * responder (defer); si la aplicación externa no contesta, `sides:enviar-webhooks` reintenta.
 */
class NotificarMonitor
{
    public static function cambio(?string $codisb, string $motivo, int|string|null $pedidoId = null): void
    {
        if (! $codisb) {
            return;
        }

        try {
            event(new MonitorActualizado($codisb, $motivo, $pedidoId));
        } catch (Throwable $e) {
            Log::warning("MONITOR -> NO SE PUDO NOTIFICAR POR WEBSOCKET ({$motivo}): ".$e->getMessage());
        }

        self::webhooks($codisb, $motivo, $pedidoId);
    }

    private static function webhooks(string $codisb, string $motivo, int|string|null $id): void
    {
        try {
            $webhooks = app(WebhooksService::class);
            $entregas = $webhooks->registrar($codisb, $motivo, $id);
        } catch (Throwable $e) {
            Log::warning("WEBHOOKS -> NO SE PUDO REGISTRAR EL AVISO ({$motivo}): ".$e->getMessage());

            return;
        }

        if ($entregas->isNotEmpty()) {
            defer(fn () => $entregas->each(fn ($entrega) => $webhooks->enviar($entrega)));
        }
    }
}
