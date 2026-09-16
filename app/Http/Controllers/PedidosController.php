<?php

namespace App\Http\Controllers;

use App\Models\Seped\Pedido;
use App\Models\Sides\SidesCfg;
use App\Services\Pedidos\PedidosException;
use App\Services\Pedidos\PedidosService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PedidosController extends Controller
{
    public function __construct(private readonly PedidosService $pedidos)
    {
    }

    public function index(Request $request): View
    {
        $codisb = $request->user()->codisb;
        $filtros = [
            'texto' => trim((string) $request->query('buscar', '')),
            'estado' => trim((string) $request->query('estado', '')),
            'desde' => $this->fecha($request->query('desde')),
            'hasta' => $this->fecha($request->query('hasta')),
        ];

        $this->pedidos->anularFacturandoViejos($codisb);

        return view('pedidos.index', [
            'pedidos' => $this->pedidos->listar($codisb, $filtros),
            'contadores' => $this->pedidos->contadores($codisb),
            'filtros' => $filtros,
        ]);
    }

    public function show(Request $request, int $pedido): View
    {
        $usuario = $request->user();

        return view('pedidos.show', [
            'pedido' => $this->buscar($request, $pedido),
            'renglones' => $this->pedidos->renglones($pedido),
            'lote' => $this->pedidos->loteDe($pedido),
            'puedeResetear' => (bool) $usuario->activarResetear,
            'puedeAnular' => (bool) $usuario->eliminarPedido,
        ]);
    }

    public function edit(Request $request, int $pedido): View
    {
        return view('pedidos.edit', [
            'pedido' => $this->buscar($request, $pedido),
            'estados' => $this->pedidos->estadosEditables(SidesCfg::query()->find($request->user()->codisb)),
        ]);
    }

    public function update(Request $request, int $pedido): RedirectResponse
    {
        $estados = $this->pedidos->estadosEditables(SidesCfg::query()->find($request->user()->codisb));
        $datos = $request->validate([
            'estado' => ['required', Rule::in($estados)],
            'fecrecibido' => ['nullable', 'date'],
            'fecpicking' => ['nullable', 'date'],
            'fecpacking' => ['nullable', 'date'],
            'feccompletado' => ['nullable', 'date'],
            'fecfacturado' => ['nullable', 'date'],
            'recipiente' => ['nullable', 'string', 'max:50'],
            'observacion' => ['nullable', 'string', 'max:500'],
        ], [
            'estado.required' => 'Elige el estado del pedido.',
            'estado.in' => 'Elige un estado de la lista.',
            '*.date' => 'Revisa las fechas: alguna no es válida.',
            'recipiente.max' => 'El recipiente no puede pasar de 50 caracteres.',
            'observacion.max' => 'La observación no puede pasar de 500 caracteres.',
        ]);

        try {
            $this->pedidos->modificar($request->user(), $pedido, [
                'estado' => $datos['estado'],
                'fecrecibido' => $datos['fecrecibido'] ?? null,
                'fecpicking' => $datos['fecpicking'] ?? null,
                'fecpacking' => $datos['fecpacking'] ?? null,
                'feccompletado' => $datos['feccompletado'] ?? null,
                'fecfacturado' => $datos['fecfacturado'] ?? null,
                'recipiente' => $datos['recipiente'] ?? null,
                'observacion' => $datos['observacion'] ?? null,
            ]);
        } catch (PedidosException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('pedidos.show', $pedido)->with('mensaje', "Pedido #{$pedido} modificado.");
    }

    public function resetear(Request $request, int $pedido): RedirectResponse
    {
        return $this->ejecutar(fn () => $this->pedidos->resetear($request->user(), $pedido), $pedido, "Pedido #{$pedido} reseteado: volvió a RECIBIDO.");
    }

    public function anular(Request $request, int $pedido): RedirectResponse
    {
        return $this->ejecutar(fn () => $this->pedidos->anular($request->user(), $pedido), $pedido, "Pedido #{$pedido} anulado.");
    }

    private function ejecutar(callable $accion, int $pedido, string $mensaje): RedirectResponse
    {
        try {
            $accion();
        } catch (PedidosException $e) {
            return redirect()->route('pedidos.show', $pedido)->with('error', $e->getMessage());
        }

        return redirect()->route('pedidos.show', $pedido)->with('mensaje', $mensaje);
    }

    private function buscar(Request $request, int $pedido): Pedido
    {
        try {
            return $this->pedidos->detalle($request->user()->codisb, $pedido);
        } catch (PedidosException $e) {
            abort(404, $e->getMessage());
        }
    }

    private function fecha(mixed $valor): string
    {
        $valor = trim((string) $valor);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) ? $valor : '';
    }
}
