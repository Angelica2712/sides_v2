<?php

namespace App\Http\Controllers;

use App\Models\Seped\Pedido;
use App\Services\BatchPicking\BatchPickingService;
use App\Services\Despacho\RenglonesPedido;
use App\Services\Picking\PickingException;
use App\Services\Picking\PickingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use App\Support\MenuSides;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PickingController extends Controller
{
    public function __construct(
        private readonly PickingService $picking,
        private readonly RenglonesPedido $renglonesPedido,
    ) {
    }

    public function index(Request $request): View
    {
        $usuario = $request->user();
        $buscar = trim((string) $request->query('buscar', ''));

        $pedidos = Pedido::query()
            ->leftJoin('sides_pedido_operacion as op', 'op.id_pedido', '=', 'pedido.id')
            ->where('pedido.codisb', $usuario->codisb)
            ->whereIn('pedido.estado', PickingService::ESTADOS_LISTA)
            // Los pedidos de un lote de Batch Picking se trabajan desde el lote.
            ->whereNotExists(function ($consulta) {
                $consulta->select(DB::raw(1))
                    ->from('sides_alcabala_lote_pedido as alp')
                    ->whereColumn('alp.numped', 'pedido.id');
            })
            ->when($buscar !== '', function ($consulta) use ($buscar) {
                $consulta->where(function ($filtro) use ($buscar) {
                    $filtro->where('pedido.id', 'like', "%{$buscar}%")
                        ->orWhere('op.recipiente', $buscar)
                        ->orWhere('pedido.codcli', 'like', "%{$buscar}%")
                        ->orWhere('pedido.nomcli', 'like', "%{$buscar}%")
                        ->orWhere('pedido.ruta', 'like', "%{$buscar}%");
                });
            })
            ->orderBy('pedido.fecprocesado')
            ->get([
                'pedido.id', 'pedido.codcli', 'pedido.nomcli', 'pedido.ruta', 'pedido.estado',
                'pedido.fecprocesado', 'pedido.numren', 'pedido.numund',
                'op.recipiente', 'op.despachador', 'op.despasignado',
            ]);

        return view('picking.index', [
            'pedidos' => $pedidos,
            'buscar' => $buscar,
            'miPedido' => $this->picking->pedidoActivoDe($usuario),
            'lotes' => MenuSides::puede($usuario, $usuario->cfg, 'batch')
                ? app(BatchPickingService::class)->lotesEnCurso($usuario->codisb)
                : collect(),
        ]);
    }

    public function tomar(Request $request, int $pedido): RedirectResponse
    {
        $datos = $request->validate(['recipiente' => ['nullable', 'string', 'max:50']]);

        try {
            $this->picking->tomar($request->user(), $pedido, $datos['recipiente'] ?? null);
        } catch (PickingException $e) {
            return redirect()->route('picking.index')->with('error', $e->getMessage());
        }

        return redirect()->route('picking.show', $pedido)->with('mensaje', "Tomaste el pedido #{$pedido}.");
    }

    public function show(Request $request, int $pedido): View|RedirectResponse
    {
        $usuario = $request->user();

        try {
            $modelo = $this->picking->abrir($usuario, $pedido);
        } catch (PickingException $e) {
            return redirect()->route('picking.index')->with('error', $e->getMessage());
        }

        $cfg = $usuario->cfg;
        $operacion = $modelo->newQuery()
            ->leftJoin('sides_pedido_operacion as op', 'op.id_pedido', '=', 'pedido.id')
            ->whereKey($pedido)
            ->first(['pedido.*', 'op.recipiente']);

        $renglones = $this->renglonesPedido->consultar($pedido, $cfg)->map(fn ($renglon) => [
            'item' => (int) $renglon->item,
            'codprod' => (string) $renglon->codprod,
            'desprod' => (string) $renglon->desprod,
            'barra' => (string) $renglon->barra,
            'cantidad' => (int) $renglon->cantidad,
            'cantdesp' => (int) $renglon->cantdesp,
            'ubicacion' => (string) $renglon->ubicacion,
            'deposito' => (string) $renglon->deposito,
            'lote' => (string) $renglon->lote,
            'vence' => RenglonesPedido::limpiarFecha($renglon->feclote),
            'marca' => (string) $renglon->marcamodelo,
            'refrigerado' => (int) $renglon->refrigerado > 0,
            'existencia' => (int) $renglon->ExiRealPick,
            'alertalote' => (int) $renglon->alertalote,
            'variosLotes' => substr_count((string) $renglon->listalote, ';') >= 2,
        ])->values()->all();

        return view('picking.show', [
            'pedido' => $operacion,
            'renglones' => $renglones,
            'cfg' => $cfg,
        ]);
    }

    public function cantidad(Request $request, int $pedido): JsonResponse
    {
        $datos = $request->validate([
            'item' => ['required', 'integer'],
            'cantidad' => ['required', 'integer', 'min:0'],
            'manual' => ['boolean'],
            'clave' => ['nullable', 'string', 'max:50'],
        ]);

        try {
            $cantdesp = $this->picking->guardarCantidad(
                $request->user(),
                $pedido,
                (int) $datos['item'],
                (int) $datos['cantidad'],
                (bool) ($datos['manual'] ?? false),
                $datos['clave'] ?? null,
            );
        } catch (PickingException $e) {
            return response()->json(['mensaje' => $e->getMessage()], 422);
        }

        return response()->json(['cantdesp' => $cantdesp]);
    }

    public function alerta(Request $request, int $pedido): JsonResponse
    {
        $datos = $request->validate(['item' => ['required', 'integer']]);

        try {
            $alerta = $this->picking->alternarAlerta($request->user(), $pedido, (int) $datos['item']);
        } catch (PickingException $e) {
            return response()->json(['mensaje' => $e->getMessage()], 422);
        }

        return response()->json(['alertalote' => $alerta]);
    }

    public function terminar(Request $request, int $pedido): RedirectResponse
    {
        try {
            $resultado = $this->picking->terminar($request->user(), $pedido);
        } catch (PickingException $e) {
            return back()->with('error', $e->getMessage());
        }

        $mensaje = match ($resultado) {
            'PACKING' => "Pedido #{$pedido} enviado a packing.",
            'PEND-FACTURA' => "Pedido #{$pedido} enviado a facturar.",
            'ANULADO' => "Pedido #{$pedido} anulado automáticamente: no tiene unidades para despachar.",
        };

        return redirect()->route('picking.index')->with('mensaje', $mensaje);
    }

    public function liberar(Request $request, int $pedido): RedirectResponse
    {
        try {
            $this->picking->liberar($request->user(), $pedido);
        } catch (PickingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('picking.index')->with('mensaje', "Liberaste el pedido #{$pedido}. Otro operario puede tomarlo.");
    }
}
