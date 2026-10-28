<?php

namespace App\Support\Monitor;

use App\Events\MonitorActualizado;
use App\Events\PickingAvanzo;
use App\Services\Almacen\UbicacionesRegistradas;
use App\Services\Webhooks\WebhooksService;
use Illuminate\Support\Facades\DB;
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

    /**
     * Un despachador guardó la cantidad de un producto: lo anota la pantalla «Picking en vivo».
     * Solo WebSocket; no es un cambio de estado del pedido, así que no va a los webhooks.
     */
    public static function picking(?string $codisb, string $despachador, int $pedidoId, int $item, int $cantidad): void
    {
        if (! $codisb) {
            return;
        }

        try {
            $renglon = UbicacionesRegistradas::unir(DB::table('pedren'), 'pedren')
                ->leftJoin('sides_pedren_operacion as op', function ($join) {
                    $join->on('op.id_pedido', '=', 'pedren.id')->on('op.item', '=', 'pedren.item');
                })
                ->where('pedren.id', $pedidoId)
                ->where('pedren.item', $item)
                ->first(['pedren.desprod', 'pedren.cantidad', DB::raw(UbicacionesRegistradas::sql('op.ubicacion', 'pedren.ubicacion').' as ubicacion')]);

            event(new PickingAvanzo(
                $codisb, $pedidoId, $despachador, (string) $renglon?->desprod, (string) $renglon?->ubicacion,
                $cantidad, (int) $renglon?->cantidad,
            ));
        } catch (Throwable $e) {
            Log::warning('MONITOR -> NO SE PUDO AVISAR EL AVANCE DEL PICKING: '.$e->getMessage());
        }
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
