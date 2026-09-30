<?php

namespace App\Http\Controllers;

use App\Services\Siad\SiadPedidosService;
use App\Support\Monitor\NotificarMonitor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * API que consulta el SIAD de la droguería cada 1 o 2 minutos (ver SiadPedidosService y
 * routes/api.php): baja los pedidos PEND-FACTURA y avisa cada cambio de estado. Mismo path,
 * parámetros y respuesta que el ApiController del SIDES legacy.
 */
class SiadApiController extends Controller
{
    public function __construct(private readonly SiadPedidosService $siad)
    {
    }

    public function getPedido(Request $request): JsonResponse
    {
        [$codisb, $estado] = $this->filtros($request);

        return response()->json($this->siad->pedido($codisb, $estado));
    }

    public function getPedidoRecibido(Request $request): JsonResponse
    {
        [$codisb, $estado] = $this->filtros($request);

        return response()->json($this->siad->pedidoRecibido($codisb, $estado));
    }

    public function getPedidoFacturando(Request $request): JsonResponse
    {
        [$codisb, $estado] = $this->filtros($request);

        return response()->json($this->siad->idsEnEstado($codisb, $estado));
    }

    /** Siempre HTTP 200 con {status, msg}, como el legacy. */
    public function updPedido(Request $request): JsonResponse
    {
        $resultado = $this->siad->actualizar(json_decode($request->getContent(), true));

        if (isset($resultado['codisb'])) {
            NotificarMonitor::cambio($resultado['codisb'], 'siad.upd_pedido', $resultado['id']);
        }

        return response()->json(['status' => $resultado['status'], 'msg' => $resultado['msg']]);
    }

    /** @return array{0: string, 1: string} */
    private function filtros(Request $request): array
    {
        $codisb = (string) $request->query('codisb');
        $estado = (string) $request->query('estado');
        Log::info('SIAD API -> '.$request->route()->getName()." codisb {$codisb} estado {$estado}");

        return [$codisb, $estado];
    }
}
