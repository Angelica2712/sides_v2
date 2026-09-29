<?php

namespace App\Services\Resumen;

use App\Models\Seped\Pedido;

/**
 * Porta AdminresumenController: dromarko, droactiva y mastranto son idénticos (misma consulta,
 * mismos 6 contadores, mismo agrupado por estado), droactiva solo cambia el CSS de las tarjetas.
 */
class ResumenService
{
    /** @return array{recibido: int, picking: int, packing: int, facturando: int, facturado: int, total: int} */
    public function contadores(string $codisb): array
    {
        $porEstado = Pedido::query()
            ->where('codisb', $codisb)
            ->selectRaw('estado, COUNT(*) as total')
            ->groupBy('estado')
            ->pluck('total', 'estado');

        return [
            'recibido' => (int) ($porEstado['RECIBIDO'] ?? 0),
            'picking' => (int) ($porEstado['PICKING'] ?? 0),
            'packing' => (int) ($porEstado['PACKING'] ?? 0),
            'facturando' => (int) ($porEstado['PEND-FACTURA'] ?? 0) + (int) ($porEstado['FACTURANDO'] ?? 0),
            'facturado' => (int) ($porEstado['FACTURADO'] ?? 0),
            'total' => (int) $porEstado->sum(),
        ];
    }
}
