<?php

namespace App\Http\Controllers;

use App\Events\MonitorActualizado;
use App\Models\Seped\Pedido;
use App\Models\Sides\SidesCfg;
use App\Support\Monitor\FiltrosMonitor;
use App\Support\Monitor\TiemposPedido;
use App\Support\PartesPedido;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/**
 * Monitor de pedidos en proceso. Porta sides_droactiva AdminmonitorController@index: lee
 * directo de `pedido` de SEPED, con recipiente/despachador de sides_pedido_operacion.
 *
 * Diferencias intencionales con el legacy:
 * - El legacy incluía el estado "PEND.FACTURA" (con punto), que nunca coincide con
 *   "PEND-FACTURA": en la práctica solo mostraba RECIBIDO/PICKING/PACKING, y así se mantiene.
 * - "Alcabala" cuenta en vivo los pedidos POR-APROBAR de SEPED (legacy: contador
 *   cfg.pedidoxAprobar, que actualizaba un cron).
 * - "Facturados hoy" usa fecfacturado (legacy: fecprocesado, que es la fecha de aprobación).
 * - No se refresca por tiempo (el legacy y la primera versión de v2 recargaban la página cada
 *   60 segundos): el navegador escucha el canal sides-monitor.{codisb} y pide `contenido`
 *   apenas algo cambia. Ver App\Events\MonitorActualizado.
 * - Los filtros con nombre (sides_monitor) son pestañas que se eligen con ?filtro={id}; el
 *   legacy los aplicaba en otra pantalla (Adminmonitor2Controller). Ver FiltrosMonitor.
 */
class MonitorController extends Controller
{
    public const ESTADOS = ['RECIBIDO', 'PICKING', 'PACKING'];

    /** Tamaños de la letra de la vista Tabla, en px, que se pueden elegir desde el monitor (de 2 en 2). */
    public const LETRA_MINIMA = 12;

    public const LETRA_MAXIMA = 40;

    /** Pantalla completa del monitor. */
    public function __invoke(Request $request): View
    {
        return view('monitor.index', $this->datos($request));
    }

    /**
     * Solo la parte que cambia (indicadores, tablero, tabla). La pide resources/js/monitor.js
     * cuando llega un aviso por el WebSocket, y reemplaza ese bloque sin recargar la página,
     * así no se pierden la vista elegida, el desplazamiento ni la pantalla completa.
     */
    public function contenido(Request $request): View
    {
        return view('monitor.contenido', $this->datos($request));
    }

    /**
     * Cambia la letra de la vista Tabla para toda la droguería (sides_cfg.TamLetraMonitor, la
     * misma de Configuración) y avisa a los monitores abiertos para que la tomen al momento.
     */
    public function letra(Request $request): JsonResponse
    {
        $datos = $request->validate(['letra' => ['required', 'integer', Rule::in(range(self::LETRA_MINIMA, self::LETRA_MAXIMA, 2))]]);
        $codisb = $request->user()->codisb;

        SidesCfg::query()->whereKey($codisb)->update(['TamLetraMonitor' => $datos['letra']]);

        try {
            event(new MonitorActualizado($codisb, 'monitor.letra'));
        } catch (Throwable $e) {
            Log::warning('MONITOR -> NO SE PUDO AVISAR EL CAMBIO DE LETRA: '.$e->getMessage());
        }

        return response()->json(['letra' => (int) $datos['letra']]);
    }

    /** Letra de la vista Tabla que se usa en pantalla: la de Configuración, entre el mínimo y el máximo. */
    public static function letraDe(?SidesCfg $cfg): int
    {
        return max(self::LETRA_MINIMA, min(self::LETRA_MAXIMA, (int) ($cfg?->TamLetraMonitor ?: 14)));
    }

    /** @return array<string, mixed> */
    private function datos(Request $request): array
    {
        $usuario = $request->user();
        $codisb = $usuario->codisb;
        $ahora = Carbon::now();

        // Pedidos en proceso por ruta: de acá salen el conteo de cada pestaña y las rutas que
        // entran al filtro elegido.
        $porRuta = Pedido::query()
            ->where('codisb', $codisb)
            ->whereIn('estado', self::ESTADOS)
            ->selectRaw('ruta, COUNT(*) as total')
            ->groupBy('ruta')
            ->get()
            ->map(fn ($fila) => ['ruta' => $fila->ruta, 'total' => (int) $fila->total]);

        $filtros = FiltrosMonitor::deSucursal($codisb);
        $filtro = $filtros->buscar($request->query('filtro'));

        $pedidos = Pedido::query()
            ->leftJoin('sides_pedido_operacion as op', 'op.id_pedido', '=', 'pedido.id')
            ->where('pedido.codisb', $codisb)
            ->whereIn('pedido.estado', self::ESTADOS)
            ->when($filtro, fn ($consulta) => $consulta->whereIn(
                'pedido.ruta', FiltrosMonitor::rutasDe($filtro, $porRuta->pluck('ruta'))
            ))
            ->orderBy('pedido.fecenviado')
            ->orderBy('pedido.nomcli')
            ->select([
                'pedido.id', 'pedido.codcli', 'pedido.nomcli', 'pedido.ruta', 'pedido.estado',
                'pedido.fecenviado', 'pedido.fecprocesado', 'pedido.fecpicking', 'pedido.fecpacking',
                'pedido.numren', 'pedido.numund', 'pedido.observacion', 'pedido.codtransp', 'pedido.idori',
                'op.recipiente', 'op.despachador',
            ])
            ->paginate(100)
            // Sin esto, cuando el fragmento se pide por `contenido` los enlaces de página apuntan
            // al fragmento (sin layout ni estilos) en vez de a la pantalla del monitor.
            ->withPath(route('monitor.index'))
            ->withQueryString();

        $porEstado = Pedido::query()
            ->where('codisb', $codisb)
            ->whereIn('estado', [...self::ESTADOS, 'POR-APROBAR'])
            ->selectRaw('estado, COUNT(*) as total')
            ->groupBy('estado')
            ->pluck('total', 'estado');

        $facturadosHoy = Pedido::query()
            ->where('codisb', $codisb)
            ->where('estado', 'FACTURADO')
            ->whereBetween('fecfacturado', [
                $ahora->copy()->startOfDay()->toDateTimeString(),
                $ahora->copy()->endOfDay()->toDateTimeString(),
            ])
            ->count();

        $cfg = $usuario->cfg;
        $filas = $pedidos->getCollection();

        return [
            'cfg' => $cfg,
            'codisb' => $codisb,
            'pedidos' => $pedidos,
            'filtro' => $filtro,
            // Pestañas: "Todos" y un filtro por fila de sides_monitor, con los pedidos en proceso de cada uno.
            'pestanas' => $filtros->todos()->isEmpty() ? [] : [
                ['id' => null, 'nombre' => 'Todos', 'total' => $porRuta->sum('total')],
                ...$filtros->todos()->map(fn ($f) => [
                    'id' => $f->id,
                    'nombre' => $f->descrip,
                    'total' => $porRuta->filter(fn ($r) => FiltrosMonitor::coincide($f, $r['ruta']))->sum('total'),
                ])->all(),
            ],
            'marcas' => $filas
                ->mapWithKeys(fn (Pedido $pedido) => [$pedido->id => $filtros->marcas($pedido->ruta)])
                ->all(),
            'partes' => PartesPedido::deLista($codisb, $filas),
            // Vista tablero: una columna por estado, conservando el orden por fecha de envío.
            'columnas' => collect(self::ESTADOS)
                ->reject(fn (string $estado) => $estado === 'PACKING' && ! ($cfg?->activarPacking ?? true))
                ->mapWithKeys(fn (string $estado) => [$estado => $filas->where('estado', $estado)->values()])
                ->all(),
            'tiempos' => $filas
                ->mapWithKeys(fn (Pedido $pedido) => [$pedido->id => TiemposPedido::calcular($pedido, $ahora)])
                ->all(),
            'indicadores' => [
                ['etiqueta' => 'Alcabala', 'valor' => (int) ($porEstado['POR-APROBAR'] ?? 0), 'icono' => 'hand'],
                ['etiqueta' => 'Recibidos', 'valor' => (int) ($porEstado['RECIBIDO'] ?? 0), 'icono' => 'monitor'],
                ['etiqueta' => 'Picking', 'valor' => (int) ($porEstado['PICKING'] ?? 0), 'icono' => 'picking'],
                ['etiqueta' => 'Packing', 'valor' => (int) ($porEstado['PACKING'] ?? 0), 'icono' => 'packing'],
                ['etiqueta' => 'Facturados hoy', 'valor' => $facturadosHoy, 'icono' => 'invoice'],
            ],
            'actualizado' => $ahora,
        ];
    }
}
