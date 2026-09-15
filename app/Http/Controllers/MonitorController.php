<?php

namespace App\Http\Controllers;

use App\Models\Seped\Pedido;
use App\Support\Monitor\TiemposPedido;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

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
 */
class MonitorController extends Controller
{
    public const ESTADOS = ['RECIBIDO', 'PICKING', 'PACKING'];

    public function __invoke(Request $request): View
    {
        $usuario = $request->user();
        $codisb = $usuario->codisb;
        $ahora = Carbon::now();

        $pedidos = Pedido::query()
            ->leftJoin('sides_pedido_operacion as op', 'op.id_pedido', '=', 'pedido.id')
            ->where('pedido.codisb', $codisb)
            ->whereIn('pedido.estado', self::ESTADOS)
            ->orderBy('pedido.fecenviado')
            ->orderBy('pedido.nomcli')
            ->select([
                'pedido.id', 'pedido.codcli', 'pedido.nomcli', 'pedido.ruta', 'pedido.estado',
                'pedido.fecenviado', 'pedido.fecprocesado', 'pedido.fecpicking', 'pedido.fecpacking',
                'pedido.numren', 'pedido.numund', 'pedido.observacion', 'pedido.codtransp',
                'op.recipiente', 'op.despachador',
            ])
            ->paginate(100);

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

        return view('monitor.index', [
            'cfg' => $cfg,
            'pedidos' => $pedidos,
            // Vista tablero: una columna por estado, conservando el orden por fecha de envío.
            'columnas' => collect(self::ESTADOS)
                ->reject(fn (string $estado) => $estado === 'PACKING' && ! ($cfg?->activarPacking ?? true))
                ->mapWithKeys(fn (string $estado) => [$estado => $filas->where('estado', $estado)->values()])
                ->all(),
            'tiempos' => $pedidos->getCollection()
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
        ]);
    }
}
