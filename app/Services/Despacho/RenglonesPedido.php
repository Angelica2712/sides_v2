<?php

namespace App\Services\Despacho;

use App\Models\Sides\SidesCfg;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Renglones de un pedido para las pantallas de picking y packing: datos del producto de
 * `pedren` (SEPED) + datos de trabajo de sides_pedren_operacion, en el orden de recolección
 * configurado (sides_cfg.ordenPedSides).
 */
class RenglonesPedido
{
    public function consultar(int $pedidoId, ?SidesCfg $cfg): Collection
    {
        $orden = match ($cfg?->ordenPedSides) {
            'DESCRIPCION' => 'pedren.desprod',
            'UBICACION' => 'ubicacion',
            'MARCA' => 'pedren.marcamodelo',
            default => 'pedren.item',
        };

        return DB::table('pedren')
            ->join('sides_pedren_operacion as op', function ($join) {
                $join->on('op.id_pedido', '=', 'pedren.id')->on('op.item', '=', 'pedren.item');
            })
            ->where('pedren.id', $pedidoId)
            ->select([
                'pedren.item', 'pedren.codprod', 'pedren.desprod', 'pedren.barra', 'pedren.cantidad',
                'pedren.marcamodelo', 'pedren.refrigerado', 'pedren.psicotropico', 'pedren.listalote',
                DB::raw("COALESCE(NULLIF(op.ubicacion, ''), pedren.ubicacion) as ubicacion"),
                DB::raw("COALESCE(NULLIF(op.deposito, ''), pedren.deposito) as deposito"),
                DB::raw("COALESCE(NULLIF(op.lote, ''), pedren.lote) as lote"),
                DB::raw("COALESCE(NULLIF(op.feclote, ''), pedren.feclote) as feclote"),
                'op.cantdesp', 'op.chequeado', 'op.packing', 'op.alertalote', 'op.ExiRealPick',
            ])
            ->orderBy($orden)
            ->orderBy('pedren.item')
            ->get();
    }

    /**
     * Lotes disponibles de un producto. pedren.listalote viene del ERP como
     * "lote_vencimiento_cantidad_deposito_codprod;" repetido.
     *
     * @return list<array{valor: string, lote: string, vence: string, cantidad: int, deposito: string}>
     */
    public static function lotes(?string $listalote): array
    {
        return collect(explode(';', (string) $listalote))
            ->map(fn (string $entrada) => trim($entrada))
            ->filter()
            ->map(function (string $entrada) {
                $partes = array_pad(explode('_', $entrada), 5, '');

                return [
                    'valor' => $entrada,
                    'lote' => $partes[0],
                    'vence' => self::limpiarFecha($partes[1]),
                    'cantidad' => (int) $partes[2],
                    'deposito' => $partes[3],
                ];
            })
            ->values()
            ->all();
    }

    /** El legacy guarda fechas de lote con horas de relleno ("12:00:00 AM", "00:00:00"). */
    public static function limpiarFecha(?string $fecha): string
    {
        return trim(str_replace(['12:00:00 AM', '12:00:00 PM', '00:00:00', '12:00AM'], '', (string) $fecha));
    }
}
