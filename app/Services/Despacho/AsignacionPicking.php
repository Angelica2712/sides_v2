<?php

namespace App\Services\Despacho;

use App\Models\Seped\Pedido;
use App\Models\Sides\SidesPedidoOperacion;
use App\Models\Sides\SidesPedrenOperacion;
use App\Models\Sides\SidesUsers;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Pone un pedido en picking a nombre de un operario. La usan el picking de un pedido y el
 * inicio de un lote de Batch Picking (legacy mastranto: iniciarPickingPedido). El llamador
 * valida las reglas de negocio y bloquea las filas.
 */
class AsignacionPicking
{
    public function asignar(Pedido $pedido, SidesUsers $usuario, string $recipiente): void
    {
        // Retomar un pedido que ya estaba en picking no reinicia fecpicking (el legacy la
        // sobrescribía y el monitor perdía el inicio real).
        if ($pedido->estado !== 'PICKING') {
            Pedido::query()->whereKey($pedido->id)->update(['estado' => 'PICKING', 'fecpicking' => Carbon::now()]);
        }

        SidesPedidoOperacion::query()->updateOrCreate(['id_pedido' => $pedido->id], [
            'codisb' => $pedido->codisb,
            'recipiente' => $recipiente,
            'despachador' => $usuario->name,
            'embalador' => '',
            'despasignado' => 1,
        ]);

        $this->prepararRenglones($pedido, $recipiente);
    }

    /**
     * Crea en sides_pedren_operacion los renglones que falten (pedidos nuevos que llegan de
     * SEPED) con cantdesp/chequeado = -1 ("sin revisar"), y les asigna el recipiente.
     */
    private function prepararRenglones(Pedido $pedido, string $recipiente): void
    {
        $existentes = SidesPedrenOperacion::query()->where('id_pedido', $pedido->id)->pluck('item')->all();

        $nuevos = DB::table('pedren')
            ->where('id', $pedido->id)
            ->whereNotIn('item', $existentes)
            ->get(['item', 'ubicacion', 'deposito', 'lote', 'feclote'])
            ->map(fn ($renglon) => [
                'id_pedido' => $pedido->id,
                'item' => $renglon->item,
                'codisb' => $pedido->codisb,
                'cantdesp' => -1,
                'chequeado' => -1,
                'recipiente' => $recipiente,
                'ubicacion' => $renglon->ubicacion,
                'deposito' => $renglon->deposito,
                'lote' => $renglon->lote,
                'feclote' => $renglon->feclote,
            ]);

        foreach ($nuevos->chunk(500) as $lote) {
            SidesPedrenOperacion::query()->insert($lote->values()->all());
        }

        SidesPedrenOperacion::query()->where('id_pedido', $pedido->id)->update(['recipiente' => $recipiente]);
    }
}
