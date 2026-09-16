<?php

namespace App\Http\Controllers;

use App\Models\Sides\SidesCfg;
use App\Models\Sides\SidesGuia;
use App\Models\Sides\SidesRuta;
use App\Services\Guias\DocumentoGuia;
use App\Services\Guias\GuiasException;
use App\Services\Guias\GuiasService;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class GuiasController extends Controller
{
    public function __construct(
        private readonly GuiasService $guias,
        private readonly DocumentoGuia $documento,
    ) {
    }

    public function index(Request $request): View
    {
        $codisb = $request->user()->codisb;
        $filtros = [
            'fecha' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('fecha')) ? (string) $request->query('fecha') : '',
            'estado' => in_array($request->query('estado'), GuiasService::ESTADOS, true) ? (string) $request->query('estado') : '',
            'ruta' => trim((string) $request->query('ruta', '')),
            'chofer' => trim((string) $request->query('chofer', '')),
        ];

        return view('guias.index', [
            'guias' => $this->guias->listar($codisb, $filtros),
            'filtros' => $filtros,
            ...$this->guias->opcionesFiltro($codisb),
        ]);
    }

    public function create(Request $request): View
    {
        $codisb = $request->user()->codisb;

        return view('guias.create', [
            'choferes' => $this->guias->choferes($codisb),
            'rutas' => SidesRuta::query()->where('codisb', $codisb)->orderBy('nombre')->get(['id', 'nombre']),
            'pendientes' => $this->guias->pedidosPendientes($codisb)->count(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'fecha' => ['required', 'date'],
            'chofer' => ['required', 'string'],
            'ruta' => ['required', 'integer'],
        ], [
            'fecha.*' => 'Escribe la fecha de la guía.',
            'chofer.required' => 'Elige el chofer.',
            'ruta.*' => 'Elige la ruta.',
        ]);

        try {
            $guia = $this->guias->crear($request->user()->codisb, $datos['fecha'], $datos['chofer'], (int) $datos['ruta']);
        } catch (GuiasException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('guias.show', $guia->id)->with('mensaje', "Guía #{$guia->id} creada.");
    }

    public function show(Request $request, int $guia): View
    {
        $registro = $this->guia($request, $guia);
        $clientes = $this->guias->clientes($registro);

        return view('guias.show', [
            'guia' => $registro,
            'clientes' => $clientes,
            'pendientes' => $this->guias->pedidosParaAgregar($registro),
            'choferes' => $this->guias->choferes($registro->codisb),
        ]);
    }

    public function edit(Request $request, int $guia): View
    {
        $registro = $this->guia($request, $guia);

        return view('guias.edit', ['guia' => $registro, 'choferes' => $this->guias->choferes($registro->codisb)]);
    }

    public function update(Request $request, int $guia): RedirectResponse
    {
        $registro = $this->guia($request, $guia);
        $datos = $request->validate([
            'fecha' => ['required', 'date'],
            'chofer' => ['required', 'string'],
            'auxiliar' => ['nullable', 'string'],
            'fecha_salida' => ['nullable', 'date'],
            'unidad' => ['nullable', 'string', 'max:100'],
        ], [
            'fecha.*' => 'Escribe la fecha de la guía.',
            'chofer.required' => 'Elige el chofer.',
            'fecha_salida.date' => 'La fecha de salida no es válida.',
            'unidad.max' => 'La unidad no puede pasar de 100 caracteres.',
        ]);

        return $this->ejecutar(function () use ($registro, $datos) {
            $this->guias->actualizar($registro, [
                'fecha' => $datos['fecha'],
                'chofer' => $datos['chofer'],
                'auxiliar' => $datos['auxiliar'] ?? null,
                'fecha_salida' => $datos['fecha_salida'] ?? null,
                'unidad' => $datos['unidad'] ?? null,
            ]);

            return redirect()->route('guias.show', $registro->id)->with('mensaje', "Guía #{$registro->id} actualizada.");
        });
    }

    public function destroy(Request $request, int $guia): RedirectResponse
    {
        $registro = $this->guia($request, $guia);

        return $this->ejecutar(function () use ($registro) {
            $this->guias->eliminar($registro);

            return redirect()->route('guias.index')->with('mensaje', "Guía #{$registro->id} eliminada. Sus pedidos quedaron pendientes de despacho.");
        });
    }

    public function separar(Request $request, int $guia): RedirectResponse
    {
        $registro = $this->guia($request, $guia);
        $datos = $request->validate([
            'clientes' => ['required', 'array'],
            'clientes.*' => ['string'],
            'chofer' => ['required', 'string'],
        ], [
            'clientes.required' => 'Marca los clientes que pasan a la guía nueva.',
            'chofer.required' => 'Elige el chofer de la guía nueva.',
        ]);

        return $this->ejecutar(function () use ($registro, $datos) {
            $nueva = $this->guias->separar($registro, $datos['clientes'], $datos['chofer']);

            return redirect()->route('guias.show', $nueva->id)->with('mensaje', "Guía #{$nueva->id} creada con los clientes separados de la guía #{$registro->id}.");
        });
    }

    public function agregarPedidos(Request $request, int $guia): RedirectResponse
    {
        $registro = $this->guia($request, $guia);
        $datos = $request->validate([
            'pedidos' => ['required', 'array'],
            'pedidos.*' => ['integer'],
        ], ['pedidos.required' => 'Marca los pedidos que quieres agregar.']);

        return $this->ejecutar(function () use ($registro, $datos) {
            $cantidad = $this->guias->agregarPedidos($registro, $datos['pedidos']);

            return back()->with('mensaje', $cantidad === 1 ? '1 pedido agregado a la guía.' : "{$cantidad} pedidos agregados a la guía.");
        });
    }

    public function quitarPedido(Request $request, int $guia, int $pedido): RedirectResponse
    {
        $registro = $this->guia($request, $guia);

        return $this->ejecutar(function () use ($registro, $pedido) {
            $this->guias->quitarPedido($registro, $pedido);

            return back()->with('mensaje', "Pedido #{$pedido} quitado de la guía: quedó pendiente de despacho.");
        });
    }

    public function quitarCliente(Request $request, int $guia): RedirectResponse
    {
        $registro = $this->guia($request, $guia);
        $codcli = (string) $request->validate(['codcli' => ['required', 'string']])['codcli'];

        return $this->ejecutar(function () use ($registro, $codcli) {
            $this->guias->quitarCliente($registro, $codcli);

            return back()->with('mensaje', 'Cliente quitado de la guía: sus pedidos quedaron pendientes de despacho.');
        });
    }

    /** Entrega un bulto (etiqueta) o todos los de un cliente (codcli). */
    public function entregar(Request $request, int $guia): RedirectResponse
    {
        $registro = $this->guia($request, $guia);
        $datos = $request->validate([
            'etiqueta' => ['required_without:codcli', 'nullable', 'string', 'max:100'],
            'codcli' => ['required_without:etiqueta', 'nullable', 'string'],
        ]);

        return $this->ejecutar(function () use ($registro, $datos) {
            $cantidad = $this->guias->entregar($registro, $datos['etiqueta'] ?? null, $datos['codcli'] ?? null, exigirCargado: false);

            return back()->with('mensaje', $cantidad === 1 ? 'Bulto marcado como entregado.' : "{$cantidad} bultos marcados como entregados.");
        });
    }

    public function reiniciar(Request $request, int $guia): RedirectResponse
    {
        $registro = $this->guia($request, $guia);
        $etiqueta = (string) $request->validate(['etiqueta' => ['required', 'string', 'max:100']])['etiqueta'];

        return $this->ejecutar(function () use ($registro, $etiqueta) {
            $this->guias->reiniciar($registro, $etiqueta);

            return back()->with('mensaje', "El bulto {$etiqueta} volvió a pendiente de carga.");
        });
    }

    public function imprimir(Request $request, int $guia): View
    {
        $registro = $this->guia($request, $guia);

        return view('guias.imprimir', [
            'guia' => $registro,
            'cfg' => SidesCfg::query()->find($registro->codisb),
            'clientes' => $this->documento->clientes($registro),
            'emision' => Carbon::now(),
        ]);
    }

    public function excel(Request $request, int $guia): BinaryFileResponse
    {
        $registro = $this->guia($request, $guia);

        return response()->download($this->documento->excel($registro), "guia_{$registro->id}.xlsx")->deleteFileAfterSend();
    }

    private function guia(Request $request, int $id): SidesGuia
    {
        try {
            return $this->guias->buscar($request->user()->codisb, $id);
        } catch (GuiasException $e) {
            abort(404, $e->getMessage());
        }
    }

    private function ejecutar(Closure $accion): RedirectResponse
    {
        try {
            return $accion();
        } catch (GuiasException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }
}
