<?php

namespace App\Http\Controllers;

use App\Services\Guias\DespachoService;
use App\Services\Guias\GuiasException;
use App\Services\Guias\GuiasService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Carga (activarGuiaCarga) y descarga (activarGuiaDescarga) de las guías en curso. */
class DespachoController extends Controller
{
    public function __construct(
        private readonly DespachoService $despacho,
        private readonly GuiasService $guias,
    ) {
    }

    public function index(Request $request): View
    {
        $usuario = $request->user();

        return view('despacho.index', [
            'guias' => $this->despacho->guias($usuario),
            'esChofer' => $this->despacho->choferDe($usuario) !== null,
            'puedeCargar' => (bool) $usuario->activarGuiaCarga,
            'puedeDescargar' => (bool) $usuario->activarGuiaDescarga,
        ]);
    }

    public function show(Request $request, int $guia, string $fase): View|RedirectResponse
    {
        $this->exigirPermiso($request, $fase);
        $registro = $this->guia($request, $guia);

        $pendientes = $this->despacho->pendientesDeCarga($registro);
        if ($fase === 'descarga' && $pendientes > 0) {
            return redirect()->route('despacho.index')->with('error', "Guía #{$registro->id}: faltan {$pendientes} bultos por cargar. Carga todos antes de empezar la entrega.");
        }

        return view('despacho.show', [
            'guia' => $registro,
            'fase' => $fase,
            'clientes' => $this->despacho->clientes($registro, $fase),
            'pendientesCarga' => $pendientes,
        ]);
    }

    public function cargar(Request $request, int $guia): RedirectResponse
    {
        $this->exigirPermiso($request, 'carga');
        $registro = $this->guia($request, $guia);
        $codigo = (string) $request->validate(['etiqueta' => ['required', 'string', 'max:100']], ['etiqueta.required' => 'Escanea el código del bulto.'])['etiqueta'];

        try {
            $bulto = $this->guias->cargar($registro, $codigo);
        } catch (GuiasException $e) {
            return $this->volver($registro->id, 'carga', 'error', $e->getMessage());
        }

        return $this->volver($registro->id, 'carga', 'exito', "Bulto {$bulto->etiqueta} cargado ({$bulto->nomcli}).");
    }

    /** Entrega un bulto escaneado o, con codcli, todos los bultos cargados del cliente. */
    public function entregar(Request $request, int $guia): RedirectResponse
    {
        $this->exigirPermiso($request, 'descarga');
        $registro = $this->guia($request, $guia);
        $datos = $request->validate([
            'etiqueta' => ['required_without:codcli', 'nullable', 'string', 'max:100'],
            'codcli' => ['required_without:etiqueta', 'nullable', 'string'],
        ], ['etiqueta.required_without' => 'Escanea el código del bulto.']);

        if ($this->despacho->pendientesDeCarga($registro) > 0) {
            return redirect()->route('despacho.index')->with('error', "Guía #{$registro->id}: carga todos los bultos antes de empezar la entrega.");
        }

        try {
            $cantidad = $this->guias->entregar($registro, $datos['etiqueta'] ?? null, $datos['codcli'] ?? null, exigirCargado: true);
        } catch (GuiasException $e) {
            return $this->volver($registro->id, 'descarga', 'error', $e->getMessage());
        }

        $registro->refresh();
        if ($registro->estado === 'ENTREGADO') {
            return redirect()->route('despacho.index')->with('mensaje', "Guía #{$registro->id} entregada completa.");
        }

        return $this->volver($registro->id, 'descarga', 'exito', $cantidad === 1 ? 'Bulto entregado.' : "{$cantidad} bultos entregados.");
    }

    private function guia(Request $request, int $id)
    {
        try {
            return $this->despacho->guia($request->user(), $id);
        } catch (GuiasException $e) {
            abort(404, $e->getMessage());
        }
    }

    private function exigirPermiso(Request $request, string $fase): void
    {
        $permiso = $fase === 'carga' ? 'activarGuiaCarga' : 'activarGuiaDescarga';
        abort_unless($request->user()->{$permiso}, 403, $fase === 'carga' ? 'No tienes permiso de guía de carga.' : 'No tienes permiso de guía de descarga.');
    }

    private function volver(int $guia, string $fase, string $resultado, string $mensaje): RedirectResponse
    {
        return redirect()->route('despacho.show', [$guia, $fase])
            ->with($resultado === 'exito' ? 'mensaje' : 'error', $mensaje)
            ->with('resultado', $resultado);
    }
}
