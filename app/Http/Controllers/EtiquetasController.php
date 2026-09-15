<?php

namespace App\Http\Controllers;

use App\Models\Sides\SidesCfg;
use App\Services\Etiquetas\EtiquetasException;
use App\Services\Etiquetas\EtiquetasService;
use App\Support\FormatosEtiqueta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EtiquetasController extends Controller
{
    public function __construct(private readonly EtiquetasService $etiquetas)
    {
    }

    public function index(Request $request): View
    {
        $buscar = trim((string) $request->query('buscar', ''));
        $pedido = null;
        $error = null;

        if ($buscar !== '') {
            try {
                $pedido = $this->etiquetas->buscar($request->user()->codisb, $buscar);
            } catch (EtiquetasException $e) {
                $error = $e->getMessage();
            }
        }

        return view('etiquetas.index', [
            'buscar' => $buscar,
            'pedido' => $pedido,
            'error' => $error,
            'cfg' => $this->cfg($request),
        ]);
    }

    public function generar(Request $request, int $pedido): RedirectResponse
    {
        $maximo = EtiquetasService::MAX_BULTOS;
        $datos = $request->validate([
            'bultos' => ['required', 'integer', 'min:1', "max:{$maximo}"],
        ], [
            'bultos.required' => 'Indica la cantidad de bultos.',
            'bultos.integer' => 'Los bultos deben ser un número entero.',
            'bultos.min' => 'Debe haber al menos 1 bulto.',
            'bultos.max' => "Los bultos no pueden pasar de {$maximo}.",
        ]);

        try {
            $this->etiquetas->generar($request->user(), $pedido, (int) $datos['bultos']);
        } catch (EtiquetasException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('etiquetas.imprimir', ['pedido' => $pedido, 'imprimir' => 1]);
    }

    public function imprimir(Request $request, int $pedido): View|RedirectResponse
    {
        try {
            $datos = $this->etiquetas->pedido($request->user()->codisb, $pedido);
        } catch (EtiquetasException $e) {
            return redirect()->route('etiquetas.index')->with('error', $e->getMessage());
        }

        return view('etiquetas.imprimir', [
            'pedido' => $datos,
            'cfg' => $this->cfg($request),
            'formato' => FormatosEtiqueta::de($this->cfg($request)?->formatoPersEtiq),
            'bultos' => max(1, (int) $datos->cantBultos),
            'volver' => $this->volver($request, $pedido),
            'autoImprimir' => $request->boolean('imprimir'),
        ]);
    }

    public function ticket(Request $request, int $pedido): View|RedirectResponse
    {
        abort_unless($this->cfg($request)?->activarImpTicket, 403, 'La impresión de tickets no está activa en tu droguería.');

        try {
            $datos = $this->etiquetas->pedido($request->user()->codisb, $pedido);
        } catch (EtiquetasException $e) {
            return redirect()->route('etiquetas.index')->with('error', $e->getMessage());
        }

        return view('etiquetas.ticket', [
            'pedido' => $datos,
            'renglones' => $this->etiquetas->renglonesDespachados($pedido),
            'cfg' => $this->cfg($request),
            'volver' => $this->volver($request, $pedido),
        ]);
    }

    /** Se lee de nuevo: la configuración pudo cambiar desde Administración. */
    private function cfg(Request $request): ?SidesCfg
    {
        return SidesCfg::query()->find($request->user()->codisb);
    }

    private function volver(Request $request, int $pedido): string
    {
        return $request->query('volver') === 'packing'
            ? route('packing.index')
            : route('etiquetas.index', ['buscar' => $pedido]);
    }
}
