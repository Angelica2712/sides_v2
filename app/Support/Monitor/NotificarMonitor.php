<?php

namespace App\Support\Monitor;

use App\Events\MonitorActualizado;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Único punto por el que SIDES avisa al monitor. Se llama desde los controladores, después
 * de que la transacción del servicio ya cerró: si se llamara dentro, el monitor podría pedir
 * el contenido nuevo antes de que el cambio esté visible para otras conexiones.
 *
 * Nunca deja caer la acción del operario: si Reverb está caído, el pedido igual se tomó o se
 * terminó, y el monitor se pone al día cuando el socket vuelve (resources/js/monitor.js
 * recarga al reconectar).
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
    }
}
