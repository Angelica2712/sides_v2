<?php

namespace App\Services\Webhooks;

/**
 * Eventos que SIDES avisa por webhook. La clave es el mismo `motivo` con el que los
 * controladores llaman a App\Support\Monitor\NotificarMonitor, así el catálogo no puede
 * quedar desfasado de lo que de verdad pasa: un motivo que no esté aquí no se envía.
 */
final class EventosWebhook
{
    /** @var array<string, string> */
    public const CATALOGO = [
        'picking.tomar' => 'Un operario tomó el pedido para picking',
        'picking.terminar' => 'Terminó el picking del pedido',
        'picking.liberar' => 'El pedido se liberó del picking y volvió a la cola',
        'packing.terminar' => 'Terminó el packing: el pedido pasa a facturar',
        'packing.liberar' => 'El pedido se liberó del packing',
        'pedidos.modificar' => 'Se modificaron datos del pedido',
        'pedidos.resetear' => 'El pedido se reseteó y volvió a RECIBIDO',
        'pedidos.anular' => 'El pedido se anuló',
        'batch.agrupar' => 'Se creó un lote de Batch Picking',
        'batch.liberar' => 'Pedidos en espera liberados al picking normal',
        'batch.iniciar' => 'Empezó el picking de un lote',
        'batch.anular' => 'Se anuló un lote',
        'batch.terminar' => 'Terminó el picking de un lote',
    ];

    public static function existe(string $evento): bool
    {
        return isset(self::CATALOGO[$evento]);
    }

    public static function esDeLote(string $evento): bool
    {
        return str_starts_with($evento, 'batch.');
    }
}
