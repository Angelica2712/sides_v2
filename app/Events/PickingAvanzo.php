<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Un despachador guardó la cantidad de un producto en el picking. Lo escucha la pantalla
 * «Picking en vivo» (resources/js/picking-vivo.js) para anotar el movimiento y volver a pedir
 * el avance de todos.
 *
 * Va por el mismo canal privado del monitor (sides-monitor.{codisb}, misma autorización en
 * routes/channels.php) con otro nombre de evento: el monitor solo escucha «actualizado», así
 * que no se recarga con cada producto. A diferencia de MonitorActualizado trae el movimiento:
 * es lo que se muestra en la lista de últimos movimientos y no queda guardado en la base.
 *
 * ShouldBroadcastNow por lo mismo que MonitorActualizado: no hay queue:work en producción.
 */
class PickingAvanzo implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $codisb,
        public readonly int $pedido,
        public readonly string $despachador,
        public readonly string $producto,
        public readonly string $ubicacion,
        public readonly int $cantidad,
        public readonly int $solicitado,
    ) {
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("sides-monitor.{$this->codisb}")];
    }

    public function broadcastAs(): string
    {
        return 'picking';
    }
}
