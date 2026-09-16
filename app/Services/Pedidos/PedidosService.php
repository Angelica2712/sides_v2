<?php

namespace App\Services\Pedidos;

use App\Models\Seped\Pedido;
use App\Models\Sides\SidesAlcabalaLotePedido;
use App\Models\Sides\SidesCfg;
use App\Models\Sides\SidesEtiquetaPedido;
use App\Models\Sides\SidesLogInacPacking;
use App\Models\Sides\SidesLogInacPicking;
use App\Models\Sides\SidesLogpacking;
use App\Models\Sides\SidesLogpicking;
use App\Models\Sides\SidesPedidoOperacion;
use App\Models\Sides\SidesPedrenOperacion;
use App\Models\Sides\SidesUsers;
use App\Support\FechaSeped;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Consulta y mantenimiento de pedidos. Porta AdminpedidoController: droactiva y dromarko son
 * idénticos; de mastranto se toman los filtros por estado y fecha de envío y el registro de IP.
 *
 * Cambios respecto al legacy:
 * - "Eliminar" ahora anula. El pedido es de SEPED: borrarlo desde SIDES lo quitaba también del
 *   sistema de pedidos y de su historial.
 * - Resetear borra el trabajo de SIDES (operación, renglones, logs, lote terminado y etiquetas sin
 *   cargar) y lo despachado que SIDES escribió en pedren. No borra la observación, que viene de SEPED.
 *   No se permite con el pedido en un lote de Batch Picking en curso ni con etiquetas en una guía.
 */
class PedidosService
{
    public const ESTADOS_EDITABLES = ['RECIBIDO', 'PICKING', 'PACKING', 'PEND-FACTURA', 'FACTURANDO', 'FACTURADO', 'ANULADO', 'CERRADO'];

    public const POR_PAGINA = 50;

    private const LOTES_EN_CURSO = ['ABIERTO', 'CONFIRMADO'];

    // Texto del legacy (con su ortografía) para que coincida con los pedidos ya anulados así.
    private const OBSERVACION_FACTURANDO_VIEJO = '(AUTOMATICO) PEDIDO ESTUBO MUCHO TIEMPO EN ESPERA POR FACTURAR';

    /** Legacy AnulaPedidosFacturandoViejos: los FACTURANDO procesados hace 30 a 120 días pasan a ANULADO. */
    public function anularFacturandoViejos(string $codisb): int
    {
        $hoy = Carbon::today();

        return Pedido::query()
            ->where('codisb', $codisb)
            ->where('estado', 'FACTURANDO')
            ->whereBetween('fecprocesado', [
                $hoy->copy()->subDays(120)->format('Y-m-d 00:00:00'),
                $hoy->copy()->subDays(30)->format('Y-m-d 23:00:00'),
            ])
            ->update([
                'estado' => 'ANULADO',
                'fecprocesado' => Carbon::now()->format('Y-m-d H:i:s'),
                'observacion' => self::OBSERVACION_FACTURANDO_VIEJO,
            ]);
    }

    /** @param  array{texto: string, estado: string, desde: string, hasta: string}  $filtros */
    public function listar(string $codisb, array $filtros): LengthAwarePaginator
    {
        $texto = $filtros['texto'];

        return Pedido::query()
            ->leftJoin('sides_pedido_operacion as op', 'op.id_pedido', '=', 'pedido.id')
            ->where('pedido.codisb', $codisb)
            ->when($texto !== '', function ($consulta) use ($texto) {
                $consulta->where(function ($filtro) use ($texto) {
                    $filtro->where('pedido.id', 'like', "%{$texto}%")
                        ->orWhere('pedido.codcli', 'like', "%{$texto}%")
                        ->orWhere('pedido.nomcli', 'like', "%{$texto}%")
                        ->orWhere('pedido.estado', 'like', "%{$texto}%")
                        ->orWhere('pedido.ruta', 'like', "%{$texto}%")
                        ->orWhere('op.recipiente', $texto);
                });
            })
            ->when($filtros['estado'] !== '', fn ($consulta) => $consulta->where('pedido.estado', $filtros['estado']))
            ->when($filtros['desde'] !== '', fn ($consulta) => $consulta->where('pedido.fecenviado', '>=', "{$filtros['desde']} 00:00:00"))
            ->when($filtros['hasta'] !== '', fn ($consulta) => $consulta->where('pedido.fecenviado', '<=', "{$filtros['hasta']} 23:59:59"))
            ->orderByDesc('pedido.id')
            ->select([
                'pedido.id', 'pedido.codcli', 'pedido.nomcli', 'pedido.ruta', 'pedido.estado', 'pedido.documento',
                'pedido.fecenviado', 'pedido.fecprocesado', 'pedido.numren', 'pedido.numund',
                'op.recipiente', 'op.despachador',
            ])
            ->paginate(self::POR_PAGINA)
            ->withQueryString();
    }

    /** @return Collection<string, int> cantidad de pedidos por estado */
    public function contadores(string $codisb): Collection
    {
        return Pedido::query()
            ->where('codisb', $codisb)
            ->selectRaw('estado, COUNT(*) as total')
            ->groupBy('estado')
            ->orderBy('estado')
            ->pluck('total', 'estado')
            ->map(fn ($total) => (int) $total);
    }

    public function detalle(string $codisb, int $pedidoId): Pedido
    {
        // Recipiente, responsables, bultos y cestas los guarda SIDES en sides_pedido_operacion.
        $pedido = Pedido::query()
            ->leftJoin('sides_pedido_operacion as op', 'op.id_pedido', '=', 'pedido.id')
            ->where('pedido.codisb', $codisb)
            ->where('pedido.id', $pedidoId)
            ->first([
                'pedido.*', 'op.recipiente as recipiente_sides', 'op.despachador as despachador_sides',
                'op.embalador as embalador_sides', 'op.cantBultos as bultos_sides', 'op.num_cesta_ped as cestas_sides',
            ]);

        if (! $pedido) {
            throw new PedidosException("No se encontró el pedido #{$pedidoId} en tu sucursal.");
        }

        return $pedido;
    }

    /** Renglones con lo despachado: mientras SIDES trabaja el pedido vive en sides_pedren_operacion (-1 = sin revisar). */
    public function renglones(int $pedidoId): Collection
    {
        return DB::table('pedren')
            ->leftJoin('sides_pedren_operacion as op', function ($join) {
                $join->on('op.id_pedido', '=', 'pedren.id')->on('op.item', '=', 'pedren.item');
            })
            ->where('pedren.id', $pedidoId)
            ->orderBy('pedren.item')
            ->get([
                'pedren.item', 'pedren.codprod', 'pedren.desprod', 'pedren.barra', 'pedren.cantidad', 'pedren.estado_desp', 'op.despachador',
                DB::raw("COALESCE(NULLIF(op.ubicacion, ''), pedren.ubicacion) as ubicacion"),
                DB::raw("COALESCE(NULLIF(op.lote, ''), pedren.lote) as lote"),
                DB::raw("COALESCE(NULLIF(op.feclote, ''), pedren.feclote) as feclote"),
                DB::raw('CASE WHEN op.cantdesp >= 0 THEN op.cantdesp ELSE pedren.cantdesp END as cantdesp'),
            ]);
    }

    /** Lote de Batch Picking del pedido, si tiene. */
    public function loteDe(int $pedidoId): ?object
    {
        return DB::table('sides_alcabala_lote_pedido as alp')
            ->join('sides_alcabala_lote as l', 'l.id', '=', 'alp.id_lote')
            ->where('alp.numped', $pedidoId)
            ->first(['l.id', 'l.estado']);
    }

    /** @return list<string> */
    public function estadosEditables(?SidesCfg $cfg): array
    {
        return $cfg?->tieneModulo('batch') ? [...self::ESTADOS_EDITABLES, 'ALCABALA'] : self::ESTADOS_EDITABLES;
    }

    /**
     * Cambio manual de estado y fechas (legacy "Modificar estado del pedido").
     *
     * @param  array{estado: string, fecrecibido: ?string, fecpicking: ?string, fecpacking: ?string, feccompletado: ?string, fecfacturado: ?string, recipiente: ?string, observacion: ?string}  $datos
     */
    public function modificar(SidesUsers $usuario, int $pedidoId, array $datos): void
    {
        DB::transaction(function () use ($usuario, $pedidoId, $datos) {
            $pedido = $this->pedidoBloqueado($usuario->codisb, $pedidoId);

            if ($datos['estado'] !== $pedido->estado) {
                $this->exigirSinLoteEnCurso($pedidoId);
            }

            $pedido->forceFill([
                'estado' => $datos['estado'],
                'fecrecibido' => FechaSeped::paraGuardar($datos['fecrecibido']),
                'fecpicking' => FechaSeped::paraGuardar($datos['fecpicking']),
                'fecpacking' => FechaSeped::paraGuardar($datos['fecpacking']),
                'feccompletado' => FechaSeped::paraGuardar($datos['feccompletado']),
                'fecfacturado' => FechaSeped::paraGuardar($datos['fecfacturado']),
                'observacion' => $datos['observacion'],
            ])->save();

            $operacion = SidesPedidoOperacion::query()->find($pedidoId);
            if ($operacion || filled($datos['recipiente'])) {
                SidesPedidoOperacion::query()->updateOrCreate(
                    ['id_pedido' => $pedidoId],
                    ['codisb' => $usuario->codisb, 'recipiente' => $datos['recipiente']]
                );
            }

            $this->registrar('MODIFICADO', $pedidoId, $usuario, "ESTADO: {$datos['estado']}");
        });
    }

    public function resetear(SidesUsers $usuario, int $pedidoId): void
    {
        if (! $usuario->activarResetear) {
            throw new PedidosException('No tienes permiso para resetear pedidos.');
        }

        DB::transaction(function () use ($usuario, $pedidoId) {
            $pedido = $this->pedidoBloqueado($usuario->codisb, $pedidoId);
            $this->exigirSinLoteEnCurso($pedidoId);

            $cargada = SidesEtiquetaPedido::query()->where('numepedi', (string) $pedidoId)->whereIn('estado', ['EN GUIA', 'CARGADO', 'ENTREGADO'])->first();
            if ($cargada) {
                $guia = $cargada->guia ? "la guía #{$cargada->guia}" : 'una guía';
                throw new PedidosException("El pedido #{$pedidoId} ya está cargado en {$guia}: no se puede resetear.");
            }

            $pedido->forceFill(['estado' => 'RECIBIDO'])->save();
            // Lo único que SIDES escribe en pedren; vuelve a los valores por defecto de SEPED.
            DB::table('pedren')->where('id', $pedidoId)->update(['cantdesp' => 0, 'estado_desp' => null]);

            SidesPedidoOperacion::query()->whereKey($pedidoId)->delete();
            SidesPedrenOperacion::query()->where('id_pedido', $pedidoId)->delete();
            foreach ([SidesLogpicking::class, SidesLogpacking::class, SidesLogInacPicking::class, SidesLogInacPacking::class] as $log) {
                $log::query()->where('id_pedido', $pedidoId)->delete();
            }
            // Un pedido que quedó en un lote terminado desaparecería de Picking si no sale del lote.
            SidesAlcabalaLotePedido::query()->where('numped', $pedidoId)->delete();
            SidesEtiquetaPedido::query()->where('numepedi', (string) $pedidoId)->where('estado', 'NUEVO')->delete();

            $this->registrar('RESETEADO', $pedidoId, $usuario, "ESTADO ANTERIOR: {$pedido->getOriginal('estado')}");
        });
    }

    public function anular(SidesUsers $usuario, int $pedidoId): void
    {
        if (! $usuario->eliminarPedido) {
            throw new PedidosException('No tienes permiso para anular pedidos.');
        }

        DB::transaction(function () use ($usuario, $pedidoId) {
            $pedido = $this->pedidoBloqueado($usuario->codisb, $pedidoId);
            if ($pedido->estado === 'ANULADO') {
                throw new PedidosException("El pedido #{$pedidoId} ya está anulado.");
            }
            $this->exigirSinLoteEnCurso($pedidoId);

            $anterior = $pedido->estado;
            $pedido->forceFill(['estado' => 'ANULADO'])->save();

            $this->registrar('ANULADO', $pedidoId, $usuario, "ESTADO ANTERIOR: {$anterior}");
        });
    }

    private function pedidoBloqueado(string $codisb, int $pedidoId): Pedido
    {
        $pedido = Pedido::query()->where('codisb', $codisb)->whereKey($pedidoId)->lockForUpdate()->first();
        if (! $pedido) {
            throw new PedidosException("No se encontró el pedido #{$pedidoId} en tu sucursal.");
        }

        return $pedido;
    }

    private function exigirSinLoteEnCurso(int $pedidoId): void
    {
        $lote = $this->loteDe($pedidoId);

        if ($lote && in_array($lote->estado, self::LOTES_EN_CURSO, true)) {
            throw new PedidosException("El pedido #{$pedidoId} está en el lote #{$lote->id} de Batch Picking, que sigue en curso. Anula o termina el lote primero.");
        }
    }

    private function registrar(string $accion, int $pedidoId, SidesUsers $usuario, string $detalle): void
    {
        Log::info("PEDIDO ID: {$pedidoId} {$accion} POR: {$usuario->email} DESDE IP: ".request()->ip()." {$detalle}");
    }
}
