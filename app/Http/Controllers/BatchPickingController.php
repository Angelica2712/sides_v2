<?php

namespace App\Http\Controllers;

use App\Services\BatchPicking\BatchPickingException;
use App\Services\BatchPicking\BatchPickingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BatchPickingController extends Controller
{
    public function __construct(private readonly BatchPickingService $batch)
    {
    }

    public function index(Request $request): View
    {
        $usuario = $request->user();
        $pedidos = $this->batch->pedidosDisponibles($usuario->codisb);

        return view('batch.index', [
            'pedidos' => $pedidos,
            'renglones' => $this->batch->renglonesDe($pedidos->pluck('id')->all()),
            'lotes' => $this->batch->lotesEnCurso($usuario->codisb),
            'puedeLiberar' => (bool) $usuario->activarLiberarAlcabala,
        ]);
    }

    public function agrupar(Request $request): RedirectResponse
    {
        $datos = $request->validate(['pedidos' => ['array'], 'pedidos.*' => ['integer']]);

        try {
            $lote = $this->batch->agrupar($request->user(), $datos['pedidos'] ?? []);
        } catch (BatchPickingException $e) {
            return back()->with('error', $e->getMessage());
        }

        $cantidad = count(array_unique($datos['pedidos']));

        return redirect()->route('batch.index')
            ->with('mensaje', "Lote #{$lote->id} creado con {$cantidad} ".($cantidad === 1 ? 'pedido' : 'pedidos').'. Ya puede iniciarse su picking.');
    }

    public function liberar(Request $request): RedirectResponse
    {
        $datos = $request->validate(['pedidos' => ['array'], 'pedidos.*' => ['integer']]);

        try {
            $liberados = $this->batch->liberar($request->user(), $datos['pedidos'] ?? []);
        } catch (BatchPickingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('batch.index')
            ->with('mensaje', $liberados === 1 ? '1 pedido liberado al picking normal.' : "{$liberados} pedidos liberados al picking normal.");
    }

    public function show(Request $request, int $lote): View|RedirectResponse
    {
        try {
            $modelo = $this->batch->lote($request->user(), $lote);
        } catch (BatchPickingException $e) {
            return redirect()->route('batch.index')->with('error', $e->getMessage());
        }

        $pedidos = $this->batch->pedidosDelLote($lote);

        return view('batch.show', [
            'lote' => $modelo,
            'pedidos' => $pedidos,
            'responsable' => $pedidos->firstWhere('despasignado', 1)?->despachador,
        ]);
    }

    public function iniciar(Request $request, int $lote): RedirectResponse
    {
        $datos = $request->validate(['recipiente' => ['nullable', 'string', 'max:50']]);

        try {
            $this->batch->iniciar($request->user(), $lote, $datos['recipiente'] ?? null);
        } catch (BatchPickingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('batch.picking', $lote)->with('mensaje', "Picking del lote #{$lote} iniciado.");
    }

    public function anular(Request $request, int $lote): RedirectResponse
    {
        try {
            $this->batch->anular($request->user(), $lote);
        } catch (BatchPickingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('batch.index')->with('mensaje', "Lote #{$lote} anulado. Sus pedidos vuelven a estar en espera.");
    }

    public function picking(Request $request, int $lote): View|RedirectResponse
    {
        $usuario = $request->user();

        try {
            $modelo = $this->batch->loteEnPickingDe($usuario, $lote);
        } catch (BatchPickingException $e) {
            return redirect()->route('batch.show', $lote)->with('error', $e->getMessage());
        }

        $pedidos = $this->batch->pedidosDelLote($lote);

        return view('batch.picking', [
            'lote' => $modelo,
            'pedidos' => $pedidos,
            'recipiente' => $pedidos->first()?->recipiente,
            'productos' => $this->batch->productos($lote, $usuario->cfg)->all(),
        ]);
    }

    public function cantidad(Request $request, int $lote): JsonResponse
    {
        $datos = $request->validate([
            'producto' => ['required', 'string', 'max:200'],
            'cantidad' => ['required', 'integer', 'min:0'],
        ]);

        try {
            return response()->json($this->batch->registrarCantidad($request->user(), $lote, $datos['producto'], (int) $datos['cantidad']));
        } catch (BatchPickingException $e) {
            return response()->json(['mensaje' => $e->getMessage()], 422);
        }
    }

    public function terminar(Request $request, int $lote): RedirectResponse
    {
        try {
            $resultados = $this->batch->terminar($request->user(), $lote);
        } catch (BatchPickingException $e) {
            return back()->with('error', $e->getMessage());
        }

        $partes = array_filter([
            $resultados['PACKING'] ? "{$resultados['PACKING']} a packing" : null,
            $resultados['PEND-FACTURA'] ? "{$resultados['PEND-FACTURA']} a facturar" : null,
            $resultados['ANULADO'] ? "{$resultados['ANULADO']} anulados por no tener unidades" : null,
        ]);

        return redirect()->route('picking.index')
            ->with('mensaje', "Lote #{$lote} terminado: ".implode(', ', $partes).'.');
    }
}
