<?php

namespace App\Services\Picking;

use App\Models\Seped\Pedido;
use App\Models\Sides\SidesCfg;
use App\Models\Sides\SidesLogInacPicking;
use App\Models\Sides\SidesLogpicking;
use App\Models\Sides\SidesPedidoOperacion;
use App\Models\Sides\SidesPedrenOperacion;
use App\Models\Sides\SidesUsers;
use App\Services\Despacho\AsignacionPicking;
use App\Services\Despacho\DespachoSeped;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Picking de un pedido. Porta sides_droactiva AdminpickingController (destroy = tomar,
 * show, modificar, modalerta, terminar, liberar) sobre la base compartida:
 *
 * - En SEPED solo se escribe lo que ya escribía el legacy: pedido.estado y sus fechas, y
 *   pedren.cantdesp/estado_desp cuando la sucursal no usa packing (el picking es el cierre).
 * - El trabajo de SIDES (despachador, recipiente, cantidad revisada, alertas) va en
 *   sides_pedido_operacion / sides_pedren_operacion.
 * - Tomar, terminar y liberar bloquean la fila del pedido: dos operarios no pueden tomar el
 *   mismo pedido a la vez (el legacy no lo impedía).
 * - Los pedidos que pertenecen a un lote de Batch Picking se trabajan solo desde el lote
 *   (legacy mastranto: pedidosPickingQuery).
 *
 * Diferencias intencionales con el legacy:
 * - Solo quien tiene el pedido puede liberarlo.
 * - Terminar también registra el tiempo cuando la sucursal no usa packing (el legacy fallaba
 *   en ese caso al buscar el pedido en estado PACKING).
 * - La inactividad al liberar no se registra: el legacy dependía de un estado guardado en el
 *   navegador (localStorage) que no es confiable.
 */
class PickingService
{
    public const ESTADOS_LISTA = ['RECIBIDO', 'PICKING'];

    public const LOG_COMPLETO = 'tiempo completo';

    public const LOG_PARCIAL = 'tiempo parcial';

    /** Texto exacto del legacy (con la errata): los informes existentes agrupan por él. */
    public const LOG_PARCIAL_FINAL = 'tiempo pacial-final';

    public function __construct(
        private readonly DespachoSeped $despachoSeped,
        private readonly AsignacionPicking $asignacion,
    ) {
    }

    public function tomar(SidesUsers $usuario, int $pedidoId, ?string $recipiente): void
    {
        DB::transaction(function () use ($usuario, $pedidoId, $recipiente) {
            $pedido = $this->pedidoDeSucursal($usuario, $pedidoId);
            $this->exigirSinLote($pedidoId);

            if (! in_array($pedido->estado, self::ESTADOS_LISTA, true)) {
                throw new PickingException("El pedido #{$pedidoId} ya no está disponible para picking (estado: {$pedido->estado}).");
            }

            $operacion = SidesPedidoOperacion::query()->whereKey($pedidoId)->lockForUpdate()->first();

            if ($operacion && $operacion->despasignado && $operacion->despachador && $operacion->despachador !== $usuario->name) {
                throw new PickingException("El pedido #{$pedidoId} ya lo tomó {$operacion->despachador}.");
            }

            $otroPedido = $this->pedidoActivoDe($usuario);
            if ($otroPedido && $otroPedido !== $pedidoId) {
                throw new PickingException("Ya tienes el pedido #{$otroPedido} en picking. Termínalo o libéralo antes de tomar otro.");
            }

            if ($lote = $this->loteActivoDe($usuario)) {
                throw new PickingException("Ya tienes el lote #{$lote} de Batch Picking en curso. Termínalo antes de tomar otro pedido.");
            }

            $recipiente = trim((string) ($operacion?->recipiente ?: $recipiente));
            if ($recipiente === '') {
                throw new PickingException('Escribe el código del recipiente.');
            }

            $this->asignacion->asignar($pedido, $usuario, $recipiente);
        });
    }

    /** Abre la pantalla de picking: marca el inicio de la sesión de trabajo (fecpicking2). */
    public function abrir(SidesUsers $usuario, int $pedidoId): Pedido
    {
        return DB::transaction(function () use ($usuario, $pedidoId) {
            $pedido = $this->pedidoDeSucursal($usuario, $pedidoId);
            $operacion = $this->operacionPropia($usuario, $pedido);
            $operacion->update(['fecpicking2' => Carbon::now()]);

            return $pedido;
        });
    }

    public function guardarCantidad(SidesUsers $usuario, int $pedidoId, int $item, int $cantidad, bool $manual, ?string $clave): int
    {
        return DB::transaction(function () use ($usuario, $pedidoId, $item, $cantidad, $manual, $clave) {
            $pedido = $this->pedidoDeSucursal($usuario, $pedidoId);
            $this->operacionPropia($usuario, $pedido);

            $solicitado = DB::table('pedren')->where('id', $pedidoId)->where('item', $item)->value('cantidad');
            if ($solicitado === null) {
                throw new PickingException('Ese producto no pertenece al pedido.');
            }

            if ($cantidad < 0 || $cantidad > (int) $solicitado) {
                throw new PickingException("La cantidad debe estar entre 0 y {$solicitado}.");
            }

            // Lectura fresca: decide la clave de supervisor.
            $cfg = SidesCfg::query()->find($usuario->codisb);
            if ($manual && $cfg?->activarValPicking && ! hash_equals((string) $cfg->claveValPicking, (string) $clave)) {
                throw new PickingException('La clave de autorización no es correcta.');
            }

            SidesPedrenOperacion::query()
                ->where('id_pedido', $pedidoId)
                ->where('item', $item)
                ->update(['cantdesp' => $cantidad, 'despachador' => $usuario->name]);

            return $cantidad;
        });
    }

    /** Marca/desmarca la alerta de lote de un renglón con varios lotes. */
    public function alternarAlerta(SidesUsers $usuario, int $pedidoId, int $item): int
    {
        return DB::transaction(function () use ($usuario, $pedidoId, $item) {
            $pedido = $this->pedidoDeSucursal($usuario, $pedidoId);
            $this->operacionPropia($usuario, $pedido);

            $renglon = SidesPedrenOperacion::query()->where('id_pedido', $pedidoId)->where('item', $item)->first();
            if (! $renglon) {
                throw new PickingException('Ese producto no pertenece al pedido.');
            }

            $alerta = $renglon->alertalote ? 0 : 1;
            SidesPedrenOperacion::query()->where('id_pedido', $pedidoId)->where('item', $item)->update(['alertalote' => $alerta]);

            return $alerta;
        });
    }

    /** @return 'PACKING'|'PEND-FACTURA'|'ANULADO' */
    public function terminar(SidesUsers $usuario, int $pedidoId): string
    {
        return DB::transaction(function () use ($usuario, $pedidoId) {
            $pedido = $this->pedidoDeSucursal($usuario, $pedidoId);
            $operacion = $this->operacionPropia($usuario, $pedido);

            $renglones = SidesPedrenOperacion::query()->where('id_pedido', $pedidoId)->get(['item', 'cantdesp']);
            if ($renglones->contains(fn ($renglon) => $renglon->cantdesp < 0)) {
                throw new PickingException('Quedan productos sin revisar. Revísalos todos antes de terminar.');
            }

            $ahora = Carbon::now();
            // Se calcula antes de insertar el log de este pedido.
            $inicioSesion = $operacion->fecpicking2 ? Carbon::parse($operacion->fecpicking2) : $ahora;
            $anterior = $this->ultimoLogDelDia($usuario, $inicioSesion);

            // Lectura fresca: decide el estado siguiente.
            $resultado = $this->cerrarPedido($pedidoId, SidesCfg::query()->find($usuario->codisb));
            if ($resultado === 'ANULADO') {
                return $resultado;
            }

            $unidades = (int) $renglones->sum('cantdesp');
            $parciales = $this->totalesParciales($pedidoId);
            $numren = $renglones->count() - $parciales['numren'];
            $numund = $unidades - $parciales['numund'];

            SidesLogpicking::query()->create([
                'id_pedido' => $pedidoId,
                'usuario' => $usuario->email,
                'numren' => $numren,
                'numund' => $numund,
                'tiempo_picking' => $this->segundos($inicioSesion, $ahora),
                'fecha_del_picking' => $ahora,
                'descripcion' => $parciales['hay'] ? self::LOG_PARCIAL_FINAL : self::LOG_COMPLETO,
            ]);

            if ($anterior) {
                SidesLogInacPicking::query()->create([
                    'id_pedido' => $pedidoId,
                    'usuario' => $usuario->email,
                    'numren' => $numren,
                    'numund' => $numund,
                    'tiempo_picking_inac' => $this->segundos(Carbon::parse($anterior->fecha_del_picking), $inicioSesion),
                    'fecha_del_picking' => $inicioSesion,
                    'id_pedido_anterior' => $anterior->id_pedido,
                ]);
            }

            return $resultado;
        });
    }

    /**
     * Pasa a la siguiente etapa un pedido con todos sus renglones ya revisados: sin unidades
     * se anula; con packing va a PACKING; sin packing va a PEND-FACTURA y escribe el despacho
     * en SEPED. Lo usa también el cierre de lotes de Batch Picking. El llamador valida y bloquea.
     *
     * @return 'PACKING'|'PEND-FACTURA'|'ANULADO'
     */
    public function cerrarPedido(int $pedidoId, ?SidesCfg $cfg): string
    {
        $unidades = (int) SidesPedrenOperacion::query()->where('id_pedido', $pedidoId)->sum('cantdesp');
        $ahora = Carbon::now();

        if ($unidades <= 0) {
            Pedido::query()->whereKey($pedidoId)->update(['estado' => 'ANULADO']);
            SidesPedrenOperacion::query()->where('id_pedido', $pedidoId)->update(['packing' => 0]);

            return 'ANULADO';
        }

        if ($cfg?->activarPacking ?? true) {
            Pedido::query()->whereKey($pedidoId)->update(['estado' => 'PACKING', 'fecpacking' => $ahora]);

            return 'PACKING';
        }

        Pedido::query()->whereKey($pedidoId)->update([
            'estado' => 'PEND-FACTURA',
            'fecpacking' => $ahora,
            'feccompletado' => $ahora,
        ]);
        SidesPedrenOperacion::query()->where('id_pedido', $pedidoId)->update(['packing' => 1]);
        $this->despachoSeped->escribir($pedidoId);

        return 'PEND-FACTURA';
    }

    public function liberar(SidesUsers $usuario, int $pedidoId): void
    {
        DB::transaction(function () use ($usuario, $pedidoId) {
            $pedido = $this->pedidoDeSucursal($usuario, $pedidoId);
            $operacion = $this->operacionPropia($usuario, $pedido);
            $ahora = Carbon::now();

            $revisados = SidesPedrenOperacion::query()->where('id_pedido', $pedidoId)->where('cantdesp', '<>', -1);
            $numren = (clone $revisados)->count();

            if ($numren > 0) {
                $parciales = $this->totalesParciales($pedidoId);
                SidesLogpicking::query()->create([
                    'id_pedido' => $pedidoId,
                    'usuario' => $usuario->email,
                    'numren' => $numren - $parciales['numren'],
                    'numund' => (int) (clone $revisados)->sum('cantdesp') - $parciales['numund'],
                    'tiempo_picking' => $this->segundos(
                        $operacion->fecpicking2 ? Carbon::parse($operacion->fecpicking2) : $ahora,
                        $ahora
                    ),
                    'fecha_del_picking' => $ahora,
                    'descripcion' => self::LOG_PARCIAL,
                ]);
            }

            $operacion->update(['despachador' => '', 'despasignado' => 0]);
        });
    }

    /** Pedido suelto (no de un lote) en PICKING que el usuario tiene asignado; el legacy permite uno a la vez. */
    public function pedidoActivoDe(SidesUsers $usuario): ?int
    {
        $id = SidesPedidoOperacion::query()
            ->join('pedido', 'pedido.id', '=', 'sides_pedido_operacion.id_pedido')
            ->where('sides_pedido_operacion.codisb', $usuario->codisb)
            ->where('sides_pedido_operacion.despachador', $usuario->name)
            ->where('sides_pedido_operacion.despasignado', 1)
            ->where('pedido.estado', 'PICKING')
            ->whereNotExists(function ($consulta) {
                $consulta->select(DB::raw(1))
                    ->from('sides_alcabala_lote_pedido as alp')
                    ->whereColumn('alp.numped', 'sides_pedido_operacion.id_pedido');
            })
            ->value('sides_pedido_operacion.id_pedido');

        return $id === null ? null : (int) $id;
    }

    /** Lote de Batch Picking en curso que el usuario está pickeando. */
    public function loteActivoDe(SidesUsers $usuario): ?int
    {
        $id = DB::table('sides_alcabala_lote as l')
            ->join('sides_alcabala_lote_pedido as alp', 'alp.id_lote', '=', 'l.id')
            ->join('sides_pedido_operacion as op', 'op.id_pedido', '=', 'alp.numped')
            ->join('pedido', 'pedido.id', '=', 'alp.numped')
            ->where('l.codisb', $usuario->codisb)
            ->where('l.estado', 'CONFIRMADO')
            ->where('op.despachador', $usuario->name)
            ->where('op.despasignado', 1)
            ->where('pedido.estado', 'PICKING')
            ->value('l.id');

        return $id === null ? null : (int) $id;
    }

    private function pedidoDeSucursal(SidesUsers $usuario, int $pedidoId): Pedido
    {
        $pedido = Pedido::query()->where('codisb', $usuario->codisb)->whereKey($pedidoId)->lockForUpdate()->first();

        if (! $pedido) {
            throw new PickingException("No se encontró el pedido #{$pedidoId} en tu sucursal.");
        }

        return $pedido;
    }

    private function exigirSinLote(int $pedidoId): void
    {
        $lote = DB::table('sides_alcabala_lote_pedido')->where('numped', $pedidoId)->value('id_lote');

        if ($lote !== null) {
            throw new PickingException("El pedido #{$pedidoId} pertenece al lote #{$lote} de Batch Picking. Trabájalo desde Batch Picking.");
        }
    }

    private function operacionPropia(SidesUsers $usuario, Pedido $pedido): SidesPedidoOperacion
    {
        $this->exigirSinLote($pedido->id);

        if ($pedido->estado !== 'PICKING') {
            throw new PickingException("El pedido #{$pedido->id} no está en picking (estado: {$pedido->estado}).");
        }

        $operacion = SidesPedidoOperacion::query()->whereKey($pedido->id)->lockForUpdate()->first();

        if (! $operacion || ! $operacion->despasignado || $operacion->despachador !== $usuario->name) {
            $quien = $operacion?->despasignado && $operacion->despachador ? " Lo tiene {$operacion->despachador}." : '';
            throw new PickingException("No tienes asignado el pedido #{$pedido->id}.{$quien}");
        }

        return $operacion;
    }

    /** @return array{hay: bool, numren: int, numund: int} */
    private function totalesParciales(int $pedidoId): array
    {
        $totales = SidesLogpicking::query()
            ->where('id_pedido', $pedidoId)
            ->where('descripcion', self::LOG_PARCIAL)
            ->selectRaw('COUNT(*) as registros, COALESCE(SUM(numren), 0) as numren, COALESCE(SUM(numund), 0) as numund')
            ->first();

        return [
            'hay' => (int) $totales->registros > 0,
            'numren' => (int) $totales->numren,
            'numund' => (int) $totales->numund,
        ];
    }

    /** Último picking registrado por el usuario el mismo día, antes de esta sesión (para la inactividad). */
    private function ultimoLogDelDia(SidesUsers $usuario, CarbonInterface $inicioSesion): ?SidesLogpicking
    {
        return SidesLogpicking::query()
            ->where('usuario', $usuario->email)
            ->whereBetween('fecha_del_picking', [
                $inicioSesion->copy()->startOfDay()->toDateTimeString(),
                $inicioSesion->toDateTimeString(),
            ])
            ->orderByDesc('fecha_del_picking')
            ->first();
    }

    private function segundos(CarbonInterface $desde, CarbonInterface $hasta): int
    {
        return abs($hasta->getTimestamp() - $desde->getTimestamp());
    }
}
