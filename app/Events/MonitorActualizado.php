<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Avisa al monitor de una droguería que sus pedidos cambiaron, para que se actualice al
 * instante en vez de esperar un refresco por tiempo.
 *
 * El evento es solo una señal, no trae el pedido: quien lo escucha vuelve a pedir el
 * contenido del monitor (MonitorController@contenido), que lee la misma base de datos.
 * Mismo criterio que NuevoPedidoAlcabala en seped_v2 — con la base compartida, mandar la
 * fila entera por el socket sería duplicar el dato.
 *
 * Lo emiten los dos sistemas sobre el mismo canal:
 * - SIDES v2, cuando un operario mueve un pedido (App\Support\Monitor\NotificarMonitor).
 * - seped_v2, cuando un pedido entra a alcabala o se aprueba (App\Events\MonitorSidesActualizado
 *   de ese proyecto, con el mismo nombre de canal y de evento).
 *
 * ShouldBroadcastNow (no ShouldBroadcast) por la misma razón que el evento de alcabala en
 * seped_v2: no hay `queue:work` corriendo en producción, así que encolarlo no lo entregaría.
 */
class MonitorActualizado implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $codisb,
        public readonly string $motivo,
        public readonly int|string|null $pedidoId = null,
    ) {
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("sides-monitor.{$this->codisb}")];
    }

    public function broadcastAs(): string
    {
        return 'actualizado';
    }
}
