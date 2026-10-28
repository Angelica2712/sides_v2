<?php

namespace App\Http\Controllers;

use App\Models\Seped\Pedido;
use App\Services\Despacho\RenglonesPedido;
use App\Support\MenuSides;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * «Picking en vivo» (desde el Monitor, solo quien administra la droguería): cada despachador con su pedido, su avance, el producto
 * que está buscando y el que sigue, y en el Almacén 3D dónde está cada uno.
 *
 * Igual que el monitor, la pantalla no se refresca por tiempo: escucha el canal
 * sides-monitor.{codisb} y vuelve a pedir datos() cuando un despachador guarda una cantidad
 * (App\Events\PickingAvanzo) o un pedido entra o sale del picking (MonitorActualizado).
 */
class PickingVivoController extends Controller
{
    public function __construct(private readonly RenglonesPedido $renglonesPedido)
    {
    }

    public function index(Request $request): View
    {
        $usuario = $request->user();

        return view('monitor.picking', [
            'codisb' => $usuario->codisb,
            'cfg' => $usuario->cfg,
            'urls' => [
                'datos' => route('monitor.picking.datos'),
                'plano' => MenuSides::puede($usuario, $usuario->cfg, 'almacen') ? route('almacen.plano') : null,
            ],
        ]);
    }

    public function datos(Request $request): JsonResponse
    {
        $usuario = $request->user();
        $cfg = $usuario->cfg;

        $pedidos = Pedido::query()
            ->join('sides_pedido_operacion as op', 'op.id_pedido', '=', 'pedido.id')
            ->where('pedido.codisb', $usuario->codisb)
            ->where('pedido.estado', 'PICKING')
            ->where('op.despasignado', 1)
            ->where('op.despachador', '<>', '')
            // Los lotes de Batch Picking se recogen por producto, no por pedido.
            ->whereNotExists(function ($consulta) {
                $consulta->select(DB::raw(1))
                    ->from('sides_alcabala_lote_pedido as alp')
                    ->whereColumn('alp.numped', 'pedido.id');
            })
            ->orderBy('op.despachador')
            ->orderBy('pedido.id')
            ->get(['pedido.id', 'pedido.nomcli', 'pedido.ruta', 'op.despachador', 'op.recipiente', 'op.fecpicking2']);

        $operarios = $pedidos->map(function ($pedido) use ($cfg) {
            $renglones = $this->renglonesPedido->consultar((int) $pedido->id, $cfg);
            $pendientes = $renglones->filter(fn ($renglon) => (int) $renglon->cantdesp < 0)->values();
            $producto = fn ($renglon) => $renglon ? [
                'desprod' => (string) $renglon->desprod,
                'ubicacion' => (string) $renglon->ubicacion,
                'cantidad' => (int) $renglon->cantidad,
            ] : null;

            return [
                'pedido' => (int) $pedido->id,
                'despachador' => (string) $pedido->despachador,
                'cliente' => (string) $pedido->nomcli,
                'ruta' => (string) $pedido->ruta,
                'recipiente' => (string) $pedido->recipiente,
                'desde' => $pedido->fecpicking2 ? (string) $pedido->fecpicking2 : null,
                'productos' => $renglones->count(),
                'revisados' => $renglones->count() - $pendientes->count(),
                'unidades' => (int) $renglones->sum('cantidad'),
                'despachadas' => (int) $renglones->sum(fn ($renglon) => max(0, (int) $renglon->cantdesp)),
                'actual' => $producto($pendientes->get(0)),
                'siguiente' => $producto($pendientes->get(1)),
            ];
        })->values();

        return response()->json([
            'operarios' => $operarios,
            'hora' => now()->format('H:i:s'),
        ]);
    }
}
