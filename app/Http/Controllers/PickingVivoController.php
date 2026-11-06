<?php

namespace App\Http\Controllers;

use App\Models\Seped\Pedido;
use App\Services\Almacen\PlanoAlmacen;
use App\Services\Despacho\RenglonesPedido;
use App\Support\MenuSides;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * «Picking en vivo» (desde el Monitor quien administra la droguería, o el televisor con «Pantalla de TV»): cada despachador con su pedido, su avance, el producto
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
            // El televisor («Pantalla de TV») no entra al Monitor: sin el enlace de regreso.
            'conMonitor' => MenuSides::puede($usuario, $usuario->cfg, 'monitor'),
            'urls' => [
                'datos' => route('monitor.picking.datos'),
                'plano' => MenuSides::puede($usuario, $usuario->cfg, 'mapavivo') ? route('monitor.picking.plano') : null,
            ],
        ]);
    }

    /** El mismo plano que AlmacenController@plano, para quien ve esta pantalla. */
    public function plano(Request $request, PlanoAlmacen $plano): JsonResponse
    {
        return response()->json($plano->de($request->user()->codisb));
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
            // null: la droguería no tiene Guías y el Almacén 3D va sin andén de carga.
            'camiones' => $cfg?->tieneModulo('guias') ? $this->camiones((string) $usuario->codisb) : null,
            'hora' => now()->format('H:i:s'),
        ]);
    }

    /**
     * Camiones en el andén: las guías que todavía no salen (NUEVO, CARGANDO o CARGADO, sin hora de salida) con sus
     * pedidos y cuántos bultos de cada uno ya se montaron (sides_etiqueta_pedido.feccargado, lo
     * marca el escaneo en «Carga y descarga»). Un pedido está montado cuando subieron todos sus bultos.
     *
     * @return list<array<string, mixed>>
     */
    private function camiones(string $codisb): array
    {
        $guias = DB::table('sides_guia')
            ->where('codisb', $codisb)
            ->whereIn('estado', ['NUEVO', 'CARGANDO', 'CARGADO'])
            // El camión deja el andén cuando sale de la droguería (GuiasService::salir).
            ->where(fn ($q) => $q->whereNull('fecha_salida')->orWhere('fecha_salida', '>', now()))
            ->orderBy('id')
            ->get(['id', 'unidad', 'nomchofer', 'ruta', 'estado']);
        if ($guias->isEmpty()) {
            return [];
        }

        $bultos = DB::table('sides_etiqueta_pedido')
            ->whereIn('guia', $guias->pluck('id'))
            ->groupBy('guia', 'numepedi')
            ->orderBy('guia')
            ->orderByRaw('MIN(id)')
            ->get([
                'guia', 'numepedi',
                DB::raw('MAX(nomcli) as cliente'),
                DB::raw('COUNT(*) as bultos'),
                DB::raw('SUM(CASE WHEN feccargado IS NULL THEN 0 ELSE 1 END) as cargados'),
            ])
            ->groupBy('guia');

        return $guias
            ->filter(fn ($guia) => $bultos->has($guia->id))
            ->map(function ($guia) use ($bultos) {
                $pedidos = $bultos[$guia->id]->map(fn ($p) => [
                    'pedido' => (int) $p->numepedi,
                    'cliente' => (string) $p->cliente,
                    'bultos' => (int) $p->bultos,
                    'cargados' => (int) $p->cargados,
                ])->values();

                return [
                    'guia' => (int) $guia->id,
                    // La placa es lo primero de sides_guia.unidad («ABC123 - Camión blanco»); sin carro, la guía.
                    'placa' => trim(explode(' - ', (string) $guia->unidad)[0]) ?: "Guía #{$guia->id}",
                    'unidad' => (string) $guia->unidad,
                    'chofer' => (string) $guia->nomchofer,
                    'ruta' => (string) $guia->ruta,
                    'estado' => (string) $guia->estado,
                    'bultos' => $pedidos->sum('bultos'),
                    'cargados' => $pedidos->sum('cargados'),
                    'pedidos' => $pedidos->all(),
                ];
            })
            ->values()
            ->all();
    }
}
