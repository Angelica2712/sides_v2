<?php

namespace App\Http\Controllers;

use App\Models\Seped\Pedido;
use App\Models\Sides\SidesCfg;
use App\Models\Sides\SidesUsers;
use App\Services\Despacho\PedidosUnidos;
use App\Services\Despacho\RenglonesPedido;
use App\Services\Etiquetas\EtiquetasException;
use App\Services\Etiquetas\EtiquetasService;
use App\Services\Packing\PackingException;
use App\Services\Packing\PackingService;
use App\Support\MenuSides;
use App\Support\Monitor\NotificarMonitor;
use App\Support\PartesPedido;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PackingController extends Controller
{
    public function __construct(
        private readonly PackingService $packing,
        private readonly RenglonesPedido $renglonesPedido,
        private readonly EtiquetasService $etiquetas,
        private readonly PedidosUnidos $unidos,
    ) {
    }

    public function index(Request $request): View
    {
        $buscar = trim((string) $request->query('buscar', ''));

        $pedidos = Pedido::query()
            ->leftJoin('sides_pedido_operacion as op', 'op.id_pedido', '=', 'pedido.id')
            ->where('pedido.codisb', $request->user()->codisb)
            ->where('pedido.estado', 'PACKING')
            ->when($buscar !== '', function ($consulta) use ($buscar) {
                $consulta->where(function ($filtro) use ($buscar) {
                    $filtro->where('pedido.id', 'like', "%{$buscar}%")
                        ->orWhere('op.recipiente', $buscar)
                        ->orWhere('pedido.codcli', 'like', "%{$buscar}%")
                        ->orWhere('pedido.nomcli', 'like', "%{$buscar}%")
                        ->orWhere('pedido.idori', $buscar);
                });
            })
            ->orderBy('pedido.fecpacking')
            ->get([
                'pedido.id', 'pedido.codcli', 'pedido.nomcli', 'pedido.ruta', 'pedido.fecpacking',
                'pedido.numren', 'pedido.numund', 'pedido.idori', 'op.recipiente', 'op.despachador', 'op.embalador',
            ]);

        $usuario = $request->user();
        $partes = PartesPedido::deLista($usuario->codisb, $pedidos);

        if ($this->unidos->activo($usuario->codisb)) {
            // Una fila por pedido: en uso si alguna parte la empaca el usuario, o si otros las tienen todas.
            $pedidos = $this->unidos->agrupar($pedidos, function ($grupo) use ($usuario) {
                $abiertas = $grupo->filter(fn ($parte) => $parte->embalador);
                $mia = $abiertas->firstWhere('embalador', $usuario->name);
                $ajena = $abiertas->count() === $grupo->count() ? $abiertas->first() : null;

                return [
                    // La fila abre una parte que el usuario pueda empacar: la suya o la primera libre.
                    'id' => ($mia ?? $grupo->first(fn ($parte) => ! $parte->embalador) ?? $grupo->first())->id,
                    'embalador' => ($mia ?? $ajena)?->embalador,
                    'despachador' => $grupo->pluck('despachador')->filter()->unique()->implode(', '),
                ];
            });
        }

        return view('packing.index', [
            'pedidos' => $pedidos,
            'partes' => $partes,
            'buscar' => $buscar,
        ]);
    }

    public function show(Request $request, int $pedido): View|RedirectResponse
    {
        $usuario = $request->user();

        try {
            $unidos = $this->packing->abrirUnidos($usuario, $pedido);
        } catch (PackingException $e) {
            return redirect()->route('packing.index')->with('error', $e->getMessage());
        }

        $cfg = $usuario->cfg;
        $datosPedido = Pedido::query()
            ->leftJoin('sides_pedido_operacion as op', 'op.id_pedido', '=', 'pedido.id')
            ->whereKey($pedido)
            ->first(['pedido.*', 'op.recipiente', 'op.despachador', 'op.cantBultos', 'op.num_cesta_ped']);

        $renglones = $this->renglonesPedido->consultar($unidos, $cfg)->map(fn ($renglon) => [
            'clave' => "{$renglon->pedido}-{$renglon->item}",
            'pedido' => (int) $renglon->pedido,
            'item' => (int) $renglon->item,
            'codprod' => (string) $renglon->codprod,
            'desprod' => (string) $renglon->desprod,
            'barra' => (string) $renglon->barra,
            'cantidad' => (int) $renglon->cantidad,
            'cantdesp' => (int) $renglon->cantdesp,
            'chequeado' => max(0, (int) $renglon->chequeado),
            'lote' => (string) $renglon->lote,
            'vence' => RenglonesPedido::limpiarFecha($renglon->feclote),
            'marca' => (string) $renglon->marcamodelo,
            'refrigerado' => (int) $renglon->refrigerado > 0,
            'psicotropico' => (int) $renglon->psicotropico > 0,
            'alertalote' => (int) $renglon->alertalote,
            'lotes' => RenglonesPedido::lotes($renglon->listalote),
        ])->values()->all();

        return view('packing.show', [
            'pedido' => $datosPedido,
            'parte' => PartesPedido::deLista($usuario->codisb, [$datosPedido])[(int) $datosPedido->id] ?? null,
            'renglones' => $renglones,
            'unidos' => $unidos,
            'cfg' => $cfg,
        ]);
    }

    public function escanear(Request $request, int $pedido): JsonResponse
    {
        $datos = $request->validate([
            'item' => ['required', 'integer'],
            'unidades' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);

        return $this->responder(fn () => $this->packing->escanear(
            $request->user(), $pedido, (int) $datos['item'], (int) ($datos['unidades'] ?? 1)
        ));
    }

    public function ajustar(Request $request, int $pedido): JsonResponse
    {
        $datos = $request->validate([
            'item' => ['required', 'integer'],
            'cantidad' => ['required', 'integer', 'min:0'],
            'clave' => ['nullable', 'string', 'max:50'],
        ]);

        return $this->responder(fn () => ['cantdesp' => $this->packing->ajustar(
            $request->user(), $pedido, (int) $datos['item'], (int) $datos['cantidad'], $datos['clave'] ?? null
        )]);
    }

    public function clave(Request $request, int $pedido): JsonResponse
    {
        $datos = $request->validate(['clave' => ['nullable', 'string', 'max:50']]);

        return $this->responder(function () use ($request, $pedido, $datos) {
            $this->packing->validarClave($request->user(), $pedido, $datos['clave'] ?? null);

            return ['ok' => true];
        });
    }

    public function lote(Request $request, int $pedido): JsonResponse
    {
        $datos = $request->validate([
            'item' => ['required', 'integer'],
            'lote' => ['required', 'string', 'max:500'],
        ]);

        return $this->responder(fn () => $this->packing->cambiarLote($request->user(), $pedido, (int) $datos['item'], $datos['lote']));
    }

    public function terminar(Request $request, int $pedido): RedirectResponse
    {
        $datos = $request->validate([
            'cantBultos' => ['required', 'string', 'max:100'],
            'despachador' => ['nullable', 'string', 'max:100'],
            'embalador' => ['nullable', 'string', 'max:100'],
            'cestas' => ['nullable', 'string', 'max:500'],
        ], [
            'cantBultos.required' => 'Indica la cantidad de bultos.',
        ]);

        try {
            $resultados = $this->packing->terminarUnidos($request->user(), $pedido, [
                'cantBultos' => $datos['cantBultos'],
                'despachador' => $datos['despachador'] ?? null,
                'embalador' => $datos['embalador'] ?? null,
                'cestas' => $datos['cestas'] ?? null,
            ]);
        } catch (PackingException $e) {
            return back()->with('error', $e->getMessage());
        }

        foreach (array_keys($resultados) as $id) {
            NotificarMonitor::cambio($request->user()->codisb, 'packing.terminar', $id);
        }

        $mensaje = collect($resultados)
            ->map(fn ($resultado, $id) => $resultado === 'ANULADO'
                ? "Pedido #{$id} anulado automáticamente: no tiene unidades para despachar."
                : "Pedido #{$id} enviado a facturar.")
            ->implode(' ');

        // Con las partes unidas, los bultos y sus etiquetas van en la primera parte que se despacha.
        $conBultos = array_key_first(array_filter($resultados, fn ($resultado) => $resultado !== 'ANULADO'));

        // sides_cfg.activar_etiqueta_packing: al terminar se imprimen las etiquetas de los bultos.
        if ($conBultos !== null && $this->imprimeEtiquetasAlTerminar($request->user())) {
            try {
                $this->etiquetas->generar($request->user(), $conBultos, max(1, (int) $datos['cantBultos']));

                return redirect()
                    ->route('etiquetas.imprimir', ['pedido' => $conBultos, 'imprimir' => 1, 'volver' => 'packing'])
                    ->with('mensaje', $mensaje);
            } catch (EtiquetasException $e) {
                return redirect()->route('packing.index')
                    ->with('mensaje', $mensaje)
                    ->with('error', "No se pudieron preparar las etiquetas: {$e->getMessage()}");
            }
        }

        return redirect()->route('packing.index')->with('mensaje', $mensaje);
    }

    private function imprimeEtiquetasAlTerminar(SidesUsers $usuario): bool
    {
        $cfg = SidesCfg::query()->find($usuario->codisb);

        return $cfg && $cfg->activar_etiqueta_packing && MenuSides::puede($usuario, $cfg, 'etiquetas');
    }

    public function liberar(Request $request, int $pedido): RedirectResponse
    {
        try {
            $liberados = $this->packing->liberarUnidos($request->user(), $pedido);
        } catch (PackingException $e) {
            return back()->with('error', $e->getMessage());
        }

        foreach ($liberados as $id) {
            NotificarMonitor::cambio($request->user()->codisb, 'packing.liberar', $id);
        }

        return redirect()->route('packing.index')->with('mensaje', "Liberaste el pedido #{$pedido}. Otro empacador puede abrirlo.");
    }

    private function responder(callable $accion): JsonResponse
    {
        try {
            return response()->json($accion());
        } catch (PackingException $e) {
            return response()->json(['mensaje' => $e->getMessage()], 422);
        }
    }
}
