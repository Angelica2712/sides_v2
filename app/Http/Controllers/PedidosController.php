<?php

namespace App\Http\Controllers;

use App\Models\Seped\Pedido;
use App\Models\Sides\SidesCfg;
use App\Services\Pedidos\PedidosException;
use App\Services\Pedidos\PedidosService;
use App\Support\Monitor\NotificarMonitor;
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
        $encontrado = $this->buscar($request, $pedido);

        return view('pedidos.show', [
            'pedido' => $encontrado,
            'renglones' => $this->pedidos->renglones($pedido),
            'lote' => $this->pedidos->loteDe($pedido),
            'enErp' => $this->pedidos->enManosDelErp($encontrado),
            'puedeResetear' => (bool) $usuario->activarResetear,
            'puedeAnular' => (bool) $usuario->eliminarPedido,
        ]);
    }

    public function edit(Request $request, int $pedido): View|RedirectResponse
    {
        $encontrado = $this->buscar($request, $pedido);
        if ($this->pedidos->enManosDelErp($encontrado)) {
            return redirect()->route('pedidos.show', $pedido)
                ->with('error', "El pedido #{$pedido} está en {$encontrado->estado}: ya lo tiene el sistema administrativo y no se puede cambiar desde SIDES.");
        }

        return view('pedidos.edit', [
            'pedido' => $encontrado,
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

        NotificarMonitor::cambio($request->user()->codisb, 'pedidos.modificar', $pedido);

        return redirect()->route('pedidos.show', $pedido)->with('mensaje', "Pedido #{$pedido} modificado.");
    }

    public function agrupar(Request $request): RedirectResponse
    {
        $pedidos = array_map('intval', (array) $request->input('pedidos', []));

        try {
            $grupo = $this->pedidos->agrupar($request->user(), $pedidos);
        } catch (PedidosException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('mensaje', "Grupo #{$grupo->id} creado con ".count($pedidos).' pedidos.');
    }

    public function desagrupar(Request $request, int $grupo): RedirectResponse
    {
        try {
            $this->pedidos->desagruparFactura($request->user(), $grupo);
        } catch (PedidosException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('mensaje', "Grupo #{$grupo} deshecho.");
    }

    public function resetear(Request $request, int $pedido): RedirectResponse
    {
        return $this->ejecutar($request, fn () => $this->pedidos->resetear($request->user(), $pedido), $pedido, 'pedidos.resetear', "Pedido #{$pedido} reseteado: volvió a RECIBIDO.");
    }

    public function anular(Request $request, int $pedido): RedirectResponse
    {
        return $this->ejecutar($request, fn () => $this->pedidos->anular($request->user(), $pedido), $pedido, 'pedidos.anular', "Pedido #{$pedido} anulado.");
    }

    private function ejecutar(Request $request, callable $accion, int $pedido, string $motivo, string $mensaje): RedirectResponse
    {
        try {
            $accion();
        } catch (PedidosException $e) {
            return redirect()->route('pedidos.show', $pedido)->with('error', $e->getMessage());
        }

        NotificarMonitor::cambio($request->user()->codisb, $motivo, $pedido);

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
