<?php

namespace App\Http\Controllers;

use App\Models\Seped\Pedido;
use App\Services\BatchPicking\BatchPickingService;
use App\Services\Despacho\PedidosUnidos;
use App\Services\Despacho\RenglonesPedido;
use App\Services\Picking\PickingException;
use App\Services\Picking\PickingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use App\Support\MenuSides;
use App\Support\Monitor\NotificarMonitor;
use App\Support\PartesPedido;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PickingController extends Controller
{
    public function __construct(
        private readonly PickingService $picking,
        private readonly RenglonesPedido $renglonesPedido,
        private readonly PedidosUnidos $unidos,
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
                        ->orWhere('pedido.ruta', 'like', "%{$buscar}%")
                        ->orWhere('pedido.idori', $buscar);
                });
            })
            ->orderBy('pedido.fecprocesado')
            ->get([
                'pedido.id', 'pedido.codcli', 'pedido.nomcli', 'pedido.ruta', 'pedido.estado',
                'pedido.fecprocesado', 'pedido.numren', 'pedido.numund', 'pedido.idori',
                'op.recipiente', 'op.despachador', 'op.despasignado',
            ]);

        $partes = PartesPedido::deLista($usuario->codisb, $pedidos);

        if ($this->unidos->activo($usuario->codisb)) {
            // Una fila por pedido: tomada si alguna parte es del usuario, o si otro las tiene todas.
            $pedidos = $this->unidos->agrupar($pedidos, function ($grupo) use ($usuario) {
                $tomadas = $grupo->filter(fn ($parte) => $parte->despasignado && $parte->despachador);
                $mia = $tomadas->firstWhere('despachador', $usuario->name);
                $ajena = $tomadas->count() === $grupo->count() ? $tomadas->first() : null;

                return [
                    // La fila abre una parte que el usuario pueda trabajar: la suya o la primera libre.
                    'id' => ($mia ?? $grupo->first(fn ($parte) => ! ($parte->despasignado && $parte->despachador)) ?? $grupo->first())->id,
                    'estado' => $grupo->contains('estado', 'PICKING') ? 'PICKING' : 'RECIBIDO',
                    'despachador' => ($mia ?? $ajena)?->despachador,
                    'despasignado' => $mia || $ajena ? 1 : 0,
                ];
            });
        }

        return view('picking.index', [
            'pedidos' => $pedidos,
            'partes' => $partes,
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
            $tomados = $this->picking->tomarUnidos($request->user(), $pedido, $datos['recipiente'] ?? null);
        } catch (PickingException $e) {
            return redirect()->route('picking.index')->with('error', $e->getMessage());
        }

        $this->notificar($request, 'picking.tomar', $tomados);

        return redirect()->route('picking.show', $pedido)->with('mensaje', 'Tomaste el '.$this->nombrar($tomados).'.');
    }

    public function show(Request $request, int $pedido): View|RedirectResponse
    {
        $usuario = $request->user();

        try {
            $unidos = $this->picking->abrirUnidos($usuario, $pedido);
        } catch (PickingException $e) {
            return redirect()->route('picking.index')->with('error', $e->getMessage());
        }

        $cfg = $usuario->cfg;
        $operacion = Pedido::query()
            ->leftJoin('sides_pedido_operacion as op', 'op.id_pedido', '=', 'pedido.id')
            ->whereKey($pedido)
            ->first(['pedido.*', 'op.recipiente']);

        $filas = $this->renglonesPedido->consultar($unidos, $cfg);
        $existencias = $cfg?->mostrarExiRealPick
            ? $this->renglonesPedido->existencias($usuario->codisb, $filas->pluck('codprod'))
            : collect();

        $renglones = $filas->map(fn ($renglon) => [
            'clave' => "{$renglon->pedido}-{$renglon->item}",
            'pedido' => (int) $renglon->pedido,
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
            'existencia' => $existencias->get((string) $renglon->codprod),
            'alertalote' => (int) $renglon->alertalote,
            'variosLotes' => substr_count((string) $renglon->listalote, ';') >= 2,
        ])->values()->all();

        return view('picking.show', [
            'pedido' => $operacion,
            'parte' => PartesPedido::deLista($usuario->codisb, [$operacion])[(int) $operacion->id] ?? null,
            'renglones' => $renglones,
            'unidos' => $unidos,
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
            $resultados = $this->picking->terminarUnidos($request->user(), $pedido);
        } catch (PickingException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->notificar($request, 'picking.terminar', array_keys($resultados));

        $mensaje = collect($resultados)
            ->map(fn ($resultado, $id) => ['id' => $id, 'resultado' => $resultado])
            ->groupBy('resultado')
            ->map(function ($grupo, $resultado) {
                $nombre = ucfirst($this->nombrar($grupo->pluck('id')->all()));
                $varios = $grupo->count() > 1;

                return match ($resultado) {
                    'PACKING' => $nombre.($varios ? ' enviados a packing.' : ' enviado a packing.'),
                    'PEND-FACTURA' => $nombre.($varios ? ' enviados a facturar.' : ' enviado a facturar.'),
                    'ANULADO' => $nombre.($varios ? ' anulados automáticamente: no tienen' : ' anulado automáticamente: no tiene').' unidades para despachar.',
                };
            })
            ->implode(' ');

        if ($siguiente = $this->picking->tomarSiguienteParte($request->user(), $pedido)) {
            $this->notificar($request, 'picking.tomar', [$siguiente]);

            return redirect()->route('picking.show', $siguiente)
                ->with('mensaje', "{$mensaje} Sigues con el pedido #{$siguiente}, otra parte del mismo pedido.");
        }

        return redirect()->route('picking.index')->with('mensaje', $mensaje);
    }

    public function liberar(Request $request, int $pedido): RedirectResponse
    {
        try {
            $liberados = $this->picking->liberarUnidos($request->user(), $pedido);
        } catch (PickingException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->notificar($request, 'picking.liberar', $liberados);

        return redirect()->route('picking.index')->with('mensaje', 'Liberaste el '.$this->nombrar($liberados).'. Otro operario puede tomarlo.');
    }

    /** @param  list<int>  $pedidos */
    private function notificar(Request $request, string $evento, array $pedidos): void
    {
        foreach ($pedidos as $id) {
            NotificarMonitor::cambio($request->user()->codisb, $evento, $id);
        }
    }

    /** "pedido #500" o "pedido #500 y sus partes #501 y #502". @param  list<int>  $pedidos */
    private function nombrar(array $pedidos): string
    {
        $numeros = array_map(fn ($id) => "#{$id}", array_values($pedidos));
        $primero = array_shift($numeros);

        return $numeros === []
            ? "pedido {$primero}"
            : "pedido {$primero} y ".(count($numeros) === 1 ? 'su parte ' : 'sus partes ').implode(' y ', array_filter([implode(', ', array_slice($numeros, 0, -1)), end($numeros)]));
    }
}
