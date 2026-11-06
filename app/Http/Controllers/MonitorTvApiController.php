<?php

namespace App\Http\Controllers;

use App\Models\Seped\Pedido;
use App\Models\Sides\SidesMonitorTv;
use App\Services\Almacen\PlanoAlmacen;
use App\Services\MonitorTv\MonitorAlertasService;
use App\Services\MonitorTv\MonitorTvService;
use App\Support\MenuSides;
use App\Support\Monitor\TiemposPedido;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

/**
 * API de los televisores de la droguería (routes/api.php). Mismo contrato que la que el compañero
 * hizo en el SIDES de mastranto, para que su app solo cambie la dirección: las respuestas van en
 * {data, metadata, message} y los errores en {status, msg}.
 *
 * Públicos: monitor_solicitar_codigo y monitor_estado (vinculación). Con el token de la TV:
 * monitor, monitor_config y monitor_alertas (los del legacy) y, nuevos en v2, monitor_picking,
 * monitor_plano y monitor_canal (Picking en vivo con el Almacén 3D y el canal en vivo de Reverb).
 *
 * Cada vista se habilita por TV en SIDES → Televisores; además el módulo tiene que estar encendido
 * para la droguería. Contrato completo para la app: sides_unificado/kit_tv_almacen3d/README.md.
 */
class MonitorTvApiController extends Controller
{
    private const SOLICITUDES_POR_MINUTO = 6;

    /**
     * La TV pide un código. Público y crea filas, así que se limita por IP y no por app_uuid
     * (que lo elige el cliente y se evade cambiándolo).
     */
    public function solicitarCodigo(Request $request, MonitorTvService $servicio): JsonResponse
    {
        if (RateLimiter::tooManyAttempts('monitor_solicitar_codigo:'.$request->ip(), self::SOLICITUDES_POR_MINUTO)) {
            return $this->error(429, 'DEMASIADAS SOLICITUDES');
        }
        RateLimiter::hit('monitor_solicitar_codigo:'.$request->ip(), 60);

        $validador = Validator::make($request->all(), [
            'app_uuid' => ['required', 'uuid'],
            'plataforma' => ['nullable', 'string', 'max:64'],
            'app_version' => ['nullable', 'string', 'max:32'],
        ]);
        if ($validador->fails()) {
            return response()->json(['status' => 422, 'msg' => 'DATOS INVALIDOS', 'errores' => $validador->errors()], 422);
        }

        $respuesta = $servicio->solicitarCodigo(
            strtolower($request->input('app_uuid')),
            $request->input('plataforma'),
            $request->input('app_version'),
        );
        if (! $respuesta['ok']) {
            return $this->error(409, 'TV YA VINCULADA');
        }

        unset($respuesta['ok']);

        return $this->datos($respuesta, status: 201);
    }

    /** La TV consulta si ya la aprobaron. El app_uuid va en el cuerpo para que no quede en los logs de acceso. */
    public function estado(Request $request, MonitorTvService $servicio): JsonResponse
    {
        $validador = Validator::make($request->all(), ['app_uuid' => ['required', 'uuid']]);
        if ($validador->fails()) {
            return response()->json(['status' => 422, 'msg' => 'DATOS INVALIDOS', 'errores' => $validador->errors()], 422);
        }

        return $this->datos($servicio->estado(strtolower($request->input('app_uuid'))));
    }

    /**
     * Pedidos en RECIBIDO, PICKING y PACKING con los minutos que llevan en su etapa, y los
     * conteos. Sin la vista «monitor» responde vacío (no es un error de la TV).
     */
    public function monitor(Request $request): JsonResponse
    {
        $tv = $this->tv($request);
        $resumenVacio = ['alcabala' => 0, 'recibidos' => 0, 'picking' => 0, 'packing' => 0, 'facturado' => 0];

        if (! $tv->vistaMonitor || ! $this->modulo($request, 'monitor')) {
            return $this->datos(['resumen' => $resumenVacio, 'registros' => []], 'VISTA NO HABILITADA');
        }

        $codisb = (string) $tv->codisb;
        $ahora = Carbon::now();

        $registros = Pedido::query()
            ->leftJoin('sides_pedido_operacion as op', 'op.id_pedido', '=', 'pedido.id')
            ->where('pedido.codisb', $codisb)
            ->whereIn('pedido.estado', MonitorController::ESTADOS)
            ->orderBy('pedido.fecenviado')
            ->orderBy('pedido.nomcli')
            ->get([
                'pedido.id', 'pedido.ruta', 'pedido.estado', 'pedido.numren', 'pedido.numund',
                'pedido.fecprocesado', 'pedido.fecpicking', 'pedido.fecpacking',
                'pedido.observacion', 'pedido.codtransp', 'op.recipiente', 'op.despachador',
            ])
            ->map(fn (Pedido $pedido) => [
                'ruta' => $pedido->ruta ?: 'S/RUTA',
                'pedido' => (string) $pedido->id,
                'renglon' => (int) $pedido->numren,
                'unidad' => (int) $pedido->numund,
                'estado' => $pedido->estado,
                // Minutos en la etapa actual, contra la hora de ahora: la TV ve el número subir.
                'esperaMin' => TiemposPedido::enEstadoActual($pedido->estado, TiemposPedido::calcular($pedido, $ahora)),
                'recipiente' => $pedido->recipiente ?: '—',
                'despachador' => $pedido->despachador ?: '—',
                'transporte' => $pedido->codtransp ?: '—',
                'observacion' => $pedido->observacion ?: '—',
            ]);

        $porEstado = Pedido::query()
            ->where('codisb', $codisb)
            ->whereIn('estado', [...MonitorController::ESTADOS, 'POR-APROBAR'])
            ->selectRaw('estado, COUNT(*) as total')
            ->groupBy('estado')
            ->pluck('total', 'estado');

        return $this->datos([
            'resumen' => [
                // En v2 la alcabala se cuenta en vivo (POR-APROBAR), igual que el monitor web.
                'alcabala' => (int) ($porEstado['POR-APROBAR'] ?? 0),
                'recibidos' => (int) ($porEstado['RECIBIDO'] ?? 0),
                'picking' => (int) ($porEstado['PICKING'] ?? 0),
                'packing' => (int) ($porEstado['PACKING'] ?? 0),
                'facturado' => Pedido::query()
                    ->where('codisb', $codisb)
                    ->where('estado', 'FACTURADO')
                    ->whereBetween('fecfacturado', [$ahora->copy()->startOfDay(), $ahora->copy()->endOfDay()])
                    ->count(),
            ],
            'registros' => $registros->values(),
        ]);
    }

    /**
     * Qué muestra esta TV y cómo: sus vistas, las opciones del monitor de Configuración y, nuevo
     * en v2, la droguería, si trae Almacén 3D y cómo escuchar el canal en vivo.
     */
    public function config(Request $request): JsonResponse
    {
        $tv = $this->tv($request);
        $usuario = $request->user();
        $cfg = $usuario->cfg;
        $vistas = array_values(array_filter($tv->vistas(), fn (string $vista) => $this->vistaDisponible($request, $vista)));

        return $this->datos([
            'alias' => $tv->alias,
            'vistas' => $vistas,
            'verTitulo' => $cfg ? (bool) $cfg->MostrarTituloMonitor : true,
            'verOperador' => $cfg ? (bool) $cfg->activarVerOperadorMonitor : false,
            'verObservacion' => $cfg ? (bool) $cfg->mostrarObsMonitor : false,
            'verTransporte' => $cfg ? (bool) $cfg->mostrarTranMonitor : false,
            'tamanoLetra' => MonitorController::letraDe($cfg),
            'drogueria' => [
                'codisb' => (string) $tv->codisb,
                'nombre' => (string) $cfg?->nombre,
                'logo' => $cfg?->urlLogo(),
            ],
            'mapa' => in_array('picking', $vistas, true) && $this->modulo($request, 'almacen'),
            'tiempoReal' => [
                'canal' => "sides-monitor.{$tv->codisb}",
                'authEndpoint' => route('monitorTv.canal'),
                'reverb' => config('sides.reverb_publico'),
            ],
            'hora' => Carbon::now()->format('H:i:s'),
        ]);
    }

    /** Retrasos y rendimiento. Trae nombres de empleados: sin la vista «alertas» se rechaza. */
    public function alertas(Request $request, MonitorAlertasService $alertas, MonitorTvService $servicio): JsonResponse
    {
        $tv = $this->tv($request);

        if (! $this->vistaDisponible($request, 'alertas')) {
            $servicio->registrarFallo($tv->id, $request->ip(), 403);

            return $this->error(403, 'VISTA NO HABILITADA');
        }

        return $this->datos($alertas->generar((string) $tv->codisb));
    }

    /** Picking en vivo: cada despachador con su pedido, su avance y el producto que busca. */
    public function picking(Request $request, PickingVivoController $pickingVivo, MonitorTvService $servicio): JsonResponse
    {
        if ($rechazo = $this->sinVista($request, 'picking', $servicio)) {
            return $rechazo;
        }

        return $this->datos($pickingVivo->datos($request)->getData(true));
    }

    /** Plano del Almacén 3D (anaqueles, productos y el formato de ubicación de la droguería). */
    public function plano(Request $request, PlanoAlmacen $plano, MonitorTvService $servicio): JsonResponse
    {
        if ($rechazo = $this->sinVista($request, 'picking', $servicio)) {
            return $rechazo;
        }
        if (! $this->modulo($request, 'almacen')) {
            return $this->error(403, 'ALMACEN 3D NO HABILITADO');
        }

        return $this->datos($plano->de((string) $this->tv($request)->codisb));
    }

    /**
     * Firma para escuchar el canal privado sides-monitor.{codisb} (el `authEndpoint` de pusher-js
     * o Laravel Echo). Responde en el formato de Pusher, no en {data}. Lo usan las vistas
     * «monitor» y «picking» para refrescar al momento en vez de consultar cada tantos segundos.
     */
    public function canal(Request $request, MonitorTvService $servicio): mixed
    {
        $tv = $this->tv($request);
        if (! $this->vistaDisponible($request, 'monitor') && ! $this->vistaDisponible($request, 'picking')) {
            $servicio->registrarFallo($tv->id, $request->ip(), 403);

            return $this->error(403, 'VISTA NO HABILITADA');
        }

        $request->validate(['socket_id' => ['required', 'string'], 'channel_name' => ['required', 'string']]);
        if ($request->input('channel_name') !== "private-sides-monitor.{$tv->codisb}") {
            return $this->error(403, 'CANAL NO PERMITIDO');
        }

        return Broadcast::auth($request);
    }

    private function tv(Request $request): SidesMonitorTv
    {
        return $request->attributes->get('monitor_tv');
    }

    /**
     * La TV tiene la vista y la droguería tiene encendido el Monitor (las tres vistas salen de él;
     * Picking en vivo se entra desde el Monitor, ver MenuSides::DEPENDE_DE).
     */
    private function vistaDisponible(Request $request, string $vista): bool
    {
        return in_array($vista, $this->tv($request)->vistas(), true) && $this->modulo($request, 'monitor');
    }

    private function modulo(Request $request, string $modulo): bool
    {
        $cfg = $request->user()->cfg;

        return ! $cfg || $cfg->tieneModulo($modulo);
    }

    private function sinVista(Request $request, string $vista, MonitorTvService $servicio): ?JsonResponse
    {
        if ($this->vistaDisponible($request, $vista)) {
            return null;
        }
        $servicio->registrarFallo($this->tv($request)->id, $request->ip(), 403);

        return $this->error(403, 'VISTA NO HABILITADA');
    }

    private function datos(mixed $datos, ?string $mensaje = null, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $datos, 'metadata' => null, 'message' => $mensaje], $status);
    }

    private function error(int $status, string $mensaje): JsonResponse
    {
        return response()->json(['status' => $status, 'msg' => $mensaje], $status);
    }
}
