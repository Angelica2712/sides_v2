<?php

namespace App\Http\Controllers;

use App\Models\Sides\SidesCfg;
use App\Services\Etiquetas\EtiquetasException;
use App\Services\Etiquetas\EtiquetasService;
use App\Services\Packing\PackingException;
use App\Services\Packing\RetencionService;
use App\Support\MenuSides;
use App\Support\Monitor\NotificarMonitor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Alcabala de packing: pedidos retenidos por cliente y su salida a facturar. */
class RetenidosController extends Controller
{
    public function __construct(
        private readonly RetencionService $retencion,
        private readonly EtiquetasService $etiquetas,
    ) {
    }

    public function index(Request $request): View
    {
        return view('retenidos.index', ['retenciones' => $this->retencion->listar($request->user()->codisb)]);
    }

    /** Libera todos los pedidos de la retención, o solo el indicado en `pedido`. */
    public function liberar(Request $request, int $retencion): RedirectResponse
    {
        $datos = $request->validate([
            'pedido' => ['nullable', 'integer'],
            'bultos' => ['nullable', 'array'],
            'bultos.*' => ['nullable', 'integer', 'min:1', 'max:'.EtiquetasService::MAX_BULTOS],
        ], ['bultos.*' => 'Los bultos deben ser un número entre 1 y '.EtiquetasService::MAX_BULTOS.'.']);

        $usuario = $request->user();

        try {
            $liberado = $this->retencion->liberar(
                $usuario, $retencion, $datos['bultos'] ?? [], isset($datos['pedido']) ? (int) $datos['pedido'] : null
            );
        } catch (PackingException $e) {
            return back()->with('error', $e->getMessage());
        }

        $pedido = $liberado['pedido'];
        foreach ([$pedido, ...$liberado['unidos']] as $id) {
            NotificarMonitor::cambio($usuario->codisb, 'packing.terminar', $id);
        }

        $mensaje = $liberado['unidos'] === []
            ? "Pedido #{$pedido} enviado a facturar."
            : 'Los pedidos #'.implode(', #', $liberado['unidos'])." se unieron al #{$pedido}, que se envió a facturar.";

        // Mismo interruptor que al terminar un packing normal: las etiquetas salen cuando ya se puede facturar.
        $cfg = SidesCfg::query()->find($usuario->codisb);
        if ($cfg?->activar_etiqueta_packing && MenuSides::puede($usuario, $cfg, 'etiquetas')) {
            try {
                $this->etiquetas->generar($usuario, $pedido, max(1, (int) $this->etiquetas->pedido($usuario->codisb, $pedido)->cantBultos));
            } catch (EtiquetasException $e) {
                return redirect()->route('retenidos.index')->with('mensaje', $mensaje)
                    ->with('error', "No se pudieron preparar las etiquetas: {$e->getMessage()}");
            }

            return redirect()->route('etiquetas.imprimir', ['pedido' => $pedido, 'imprimir' => 1, 'volver' => 'packing'])->with('mensaje', $mensaje);
        }

        return redirect()->route('retenidos.index')->with('mensaje', $mensaje);
    }
}
