<?php

namespace App\Services\Despacho;

use Illuminate\Support\Facades\DB;

/**
 * Escribe en `pedren` de SEPED la cantidad despachada de cada renglón al cerrar el pedido
 * (fin de packing, o fin de picking si la sucursal no usa packing). Es la única escritura de
 * SIDES sobre pedren y replica el upd_pedren del legacy:
 * <=0 NO FACTURADO, menos de lo solicitado PARCIAL, completo FACTURADO.
 */
class DespachoSeped
{
    public function escribir(int $pedidoId): void
    {
        $renglones = DB::table('pedren')
            ->join('sides_pedren_operacion as op', function ($join) {
                $join->on('op.id_pedido', '=', 'pedren.id')->on('op.item', '=', 'pedren.item');
            })
            ->where('pedren.id', $pedidoId)
            ->get(['pedren.item', 'pedren.cantidad', 'op.cantdesp']);

        foreach ($renglones as $renglon) {
            $estadoDesp = match (true) {
                $renglon->cantdesp <= 0 => 'NO FACTURADO',
                $renglon->cantdesp < $renglon->cantidad => 'PARCIAL',
                default => 'FACTURADO',
            };

            DB::table('pedren')
                ->where('id', $pedidoId)
                ->where('item', $renglon->item)
                ->update(['cantdesp' => max(0, (int) $renglon->cantdesp), 'estado_desp' => $estadoDesp]);
        }
    }
}
