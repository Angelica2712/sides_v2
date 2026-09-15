<?php

namespace App\Services\BatchPicking;

use App\Models\Seped\Pedido;
use App\Models\Sides\SidesAlcabalaLote;
use App\Models\Sides\SidesAlcabalaLotePedido;
use App\Models\Sides\SidesAlcabalaPedidoLiberado;
use App\Models\Sides\SidesCfg;
use App\Models\Sides\SidesPedrenOperacion;
use App\Models\Sides\SidesUsers;
use App\Services\Despacho\AsignacionPicking;
use App\Services\Despacho\RenglonesPedido;
use App\Services\Picking\PickingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Batch Picking (legacy mastranto: PickingAlcabalaController, "AlcabalaPicking"). En v2 está
 * disponible para todas las droguerías: se agrupan en lotes (para recogerlos en un solo
 * recorrido del almacén) los pedidos RECIBIDO que ningún operario tomó y los que llegaron
 * en espera (estado ALCABALA, sucursales con sides_cfg.procAlcabalaPicking = 1). Los pedidos
 * en espera también se pueden liberar al picking normal.
 *
 * Flujo del lote: ABIERTO (armado) → CONFIRMADO (picking iniciado con un recipiente para
 * todos) → TERMINADO (todos sus pedidos pasaron a la etapa siguiente). Anular solo es
 * posible mientras está ABIERTO.
 *
 * En el picking del lote se registra la cantidad recogida por producto (codprod + lote de
 * producto) y se reparte entre los pedidos por antigüedad: el más antiguo se completa
 * primero y el faltante recae en los más recientes (FIFO, igual que el legacy).
 *
 * Pendiente de portar: sugerido automático y perfiles de agrupamiento.
 */
class BatchPickingService
{
    public function __construct(
        private readonly PickingService $picking,
        private readonly AsignacionPicking $asignacion,
    ) {
    }

    /** Pedidos que se pueden agrupar: en espera, o recibidos sin operario asignado, y fuera de todo lote. */
    public function pedidosDisponibles(string $codisb): Collection
    {
        return Pedido::query()
            ->leftJoin('sides_pedido_operacion as op', 'op.id_pedido', '=', 'pedido.id')
            ->where('pedido.codisb', $codisb)
            ->where(function ($consulta) {
                $consulta->where('pedido.estado', 'ALCABALA')
                    ->orWhere(function ($recibido) {
                        $recibido->where('pedido.estado', 'RECIBIDO')
                            ->where(function ($sinTomar) {
                                $sinTomar->whereNull('op.id_pedido')
                                    ->orWhere('op.despasignado', '<>', 1)
                                    ->orWhereNull('op.despachador')
                                    ->orWhere('op.despachador', '');
                            });
                    });
            })
            ->whereNotExists(function ($consulta) {
                $consulta->select(DB::raw(1))
                    ->from('sides_alcabala_lote_pedido as alp')
                    ->whereColumn('alp.numped', 'pedido.id');
            })
            ->orderBy('pedido.fecprocesado')
            ->orderBy('pedido.id')
            ->get([
                'pedido.id', 'pedido.codcli', 'pedido.nomcli', 'pedido.ruta', 'pedido.estado', 'pedido.fecha',
                'pedido.fecprocesado', 'pedido.numren', 'pedido.numund',
            ]);
    }

    /** @return array<int, Collection> renglones por pedido */
    public function renglonesDe(array $pedidoIds): array
    {
        if ($pedidoIds === []) {
            return [];
        }

        return DB::table('pedren')
            ->whereIn('id', $pedidoIds)
            ->orderBy('item')
            ->get(['id', 'codprod', 'desprod', 'ubicacion', 'cantidad'])
            ->groupBy('id')
            ->all();
    }

    /** Lotes ABIERTO/CONFIRMADO con cantidad de pedidos, responsable y recipiente. */
    public function lotesEnCurso(string $codisb): Collection
    {
        return SidesAlcabalaLote::query()
            ->where('codisb', $codisb)
            ->whereIn('estado', ['ABIERTO', 'CONFIRMADO'])
            ->withCount('pedidos')
            ->orderByDesc('fecha_creacion')
            ->get()
            ->each(function (SidesAlcabalaLote $lote) {
                $operacion = DB::table('sides_alcabala_lote_pedido as alp')
                    ->join('sides_pedido_operacion as op', 'op.id_pedido', '=', 'alp.numped')
                    ->where('alp.id_lote', $lote->id)
                    ->where('op.despasignado', 1)
                    ->first(['op.despachador', 'op.recipiente']);

                $lote->responsable = $operacion?->despachador;
                $lote->recipiente = $operacion?->recipiente;
            });
    }

    public function lote(SidesUsers $usuario, int $loteId): SidesAlcabalaLote
    {
        $lote = SidesAlcabalaLote::query()->where('codisb', $usuario->codisb)->whereKey($loteId)->first();

        if (! $lote) {
            throw new BatchPickingException("No se encontró el lote #{$loteId} en tu sucursal.");
        }

        return $lote;
    }

    /** Pedidos de un lote con su estado y datos de trabajo. */
    public function pedidosDelLote(int $loteId): Collection
    {
        return DB::table('sides_alcabala_lote_pedido as alp')
            ->join('pedido', 'pedido.id', '=', 'alp.numped')
            ->leftJoin('sides_pedido_operacion as op', 'op.id_pedido', '=', 'alp.numped')
            ->where('alp.id_lote', $loteId)
            ->orderBy('pedido.fecha')
            ->orderBy('pedido.id')
            ->get([
                'pedido.id', 'pedido.codcli', 'pedido.nomcli', 'pedido.ruta', 'pedido.estado', 'pedido.fecha',
                'pedido.numren', 'pedido.numund', 'op.recipiente', 'op.despachador', 'op.despasignado',
            ]);
    }

    /** Libera pedidos en espera al picking normal (pasan a RECIBIDO). Requiere activarLiberarAlcabala. */
    public function liberar(SidesUsers $usuario, array $pedidoIds): int
    {
        if (! $usuario->activarLiberarAlcabala) {
            throw new BatchPickingException('No tienes permiso para liberar pedidos.');
        }

        $pedidoIds = $this->limpiarIds($pedidoIds);
        if ($pedidoIds === []) {
            throw new BatchPickingException('Selecciona al menos un pedido para liberar.');
        }

        return DB::transaction(function () use ($usuario, $pedidoIds) {
            $validos = $this->pedidosDisponibles($usuario->codisb)
                ->where('estado', 'ALCABALA')
                ->whereIn('id', $pedidoIds)
                ->pluck('id')
                ->all();
            if ($validos === []) {
                throw new BatchPickingException('Los pedidos seleccionados no están en espera: los recibidos ya están disponibles en el picking normal.');
            }

            Pedido::query()->whereKey($validos)->where('estado', 'ALCABALA')->update(['estado' => 'RECIBIDO']);

            foreach ($validos as $pedidoId) {
                SidesAlcabalaPedidoLiberado::query()->updateOrCreate(['numped' => $pedidoId], [
                    'codisb' => $usuario->codisb,
                    'fecha_liberado' => Carbon::now(),
                    'liberado_por' => $usuario->name,
                ]);
            }

            return count($validos);
        });
    }

    public function agrupar(SidesUsers $usuario, array $pedidoIds, string $origen = 'MANUAL', ?int $idPerfil = null): SidesAlcabalaLote
    {
        $pedidoIds = $this->limpiarIds($pedidoIds);
        if ($pedidoIds === []) {
            throw new BatchPickingException('Selecciona al menos un pedido para agrupar.');
        }

        return DB::transaction(function () use ($usuario, $pedidoIds, $origen, $idPerfil) {
            $pedidos = Pedido::query()
                ->where('codisb', $usuario->codisb)
                ->whereKey($pedidoIds)
                ->lockForUpdate()
                ->get(['id', 'codcli', 'estado']);

            if ($pedidos->count() !== count($pedidoIds)) {
                throw new BatchPickingException('Algunos pedidos seleccionados no existen en tu sucursal.');
            }

            $yaAgrupados = SidesAlcabalaLotePedido::query()->whereIn('numped', $pedidoIds)->pluck('numped');
            if ($yaAgrupados->isNotEmpty()) {
                throw new BatchPickingException('Los pedidos #'.$yaAgrupados->implode(', #').' ya pertenecen a otro lote.');
            }

            $disponibles = $this->pedidosDisponibles($usuario->codisb)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $noDisponibles = $pedidos->pluck('id')->reject(fn ($id) => in_array((int) $id, $disponibles, true));
            if ($noDisponibles->isNotEmpty()) {
                throw new BatchPickingException('Los pedidos #'.$noDisponibles->implode(', #').' ya no están disponibles para agrupar (los tomó un operario o cambiaron de estado).');
            }

            $ahora = Carbon::now();
            $lote = SidesAlcabalaLote::query()->create([
                'codisb' => $usuario->codisb,
                'estado' => 'ABIERTO',
                'origen_creacion' => $origen,
                'id_perfil' => $idPerfil,
                'creado_por' => $usuario->name,
                'fecha_creacion' => $ahora,
            ]);

            foreach ($pedidos as $pedido) {
                SidesAlcabalaLotePedido::query()->create([
                    'id_lote' => $lote->id,
                    'numped' => $pedido->id,
                    'codcli' => $pedido->codcli,
                    'fecha_agregado' => $ahora,
                ]);
            }

            return $lote;
        });
    }

    /** Anula un lote que todavía no inició picking: sus pedidos vuelven a quedar en espera. */
    public function anular(SidesUsers $usuario, int $loteId): void
    {
        DB::transaction(function () use ($usuario, $loteId) {
            $lote = $this->loteBloqueado($usuario, $loteId);

            if ($lote->estado !== 'ABIERTO') {
                throw new BatchPickingException("El lote #{$loteId} ya inició picking y no se puede anular.");
            }

            SidesAlcabalaLotePedido::query()->where('id_lote', $loteId)->delete();
            $lote->update(['estado' => 'ANULADO']);
        });
    }

    /** Inicia el picking del lote: todos sus pedidos pasan a PICKING con un mismo recipiente. */
    public function iniciar(SidesUsers $usuario, int $loteId, ?string $recipiente): void
    {
        $recipiente = trim((string) $recipiente);
        if ($recipiente === '') {
            throw new BatchPickingException('Escribe el código del recipiente del lote.');
        }

        DB::transaction(function () use ($usuario, $loteId, $recipiente) {
            $lote = $this->loteBloqueado($usuario, $loteId);

            if ($lote->estado !== 'ABIERTO') {
                throw new BatchPickingException("El lote #{$loteId} no está disponible para iniciar picking.");
            }

            if ($pedido = $this->picking->pedidoActivoDe($usuario)) {
                throw new BatchPickingException("Ya tienes el pedido #{$pedido} en picking. Termínalo o libéralo antes de iniciar un lote.");
            }

            if ($otroLote = $this->picking->loteActivoDe($usuario)) {
                throw new BatchPickingException("Ya tienes el lote #{$otroLote} en picking. Termínalo antes de iniciar otro.");
            }

            $pedidoIds = SidesAlcabalaLotePedido::query()->where('id_lote', $loteId)->pluck('numped')->all();
            if ($pedidoIds === []) {
                throw new BatchPickingException("El lote #{$loteId} no tiene pedidos.");
            }

            $pedidos = Pedido::query()->whereKey($pedidoIds)->lockForUpdate()->get();
            $noDisponibles = $pedidos->reject(fn (Pedido $pedido) => in_array($pedido->estado, ['ALCABALA', 'RECIBIDO'], true))->pluck('id');
            if ($noDisponibles->isNotEmpty()) {
                throw new BatchPickingException('Los pedidos #'.$noDisponibles->implode(', #').' ya no están disponibles (cambiaron de estado en SEPED). Anula el lote y vuelve a armarlo.');
            }

            foreach ($pedidos as $pedido) {
                $this->asignacion->asignar($pedido, $usuario, $recipiente);
            }

            $lote->update(['estado' => 'CONFIRMADO', 'fecha_confirmado' => Carbon::now()]);
        });
    }

    /**
     * Productos del lote sumados entre todos sus pedidos: uno por código + lote de producto
     * (dos lotes del mismo producto se recogen por separado). Pendientes primero, luego el
     * orden de recolección configurado y el vencimiento más próximo.
     */
    public function productos(int $loteId, ?SidesCfg $cfg): Collection
    {
        $campoOrden = match ($cfg?->ordenPedSides) {
            'DESCRIPCION' => 'desprod',
            'UBICACION' => 'ubicacion',
            'MARCA' => 'marca',
            default => 'codprod',
        };

        return $this->renglonesDelLote($loteId)
            ->groupBy(fn ($renglon) => $this->claveProducto($renglon))
            ->map(function (Collection $grupo, string $clave) {
                $primero = $grupo->first();

                return [
                    'clave' => $clave,
                    'codprod' => (string) $primero->codprod,
                    'desprod' => (string) $primero->desprod,
                    'barra' => (string) $primero->barra,
                    'marca' => (string) $primero->marcamodelo,
                    'ubicacion' => (string) $primero->ubicacion,
                    'lote' => (string) $primero->lote,
                    'vence' => RenglonesPedido::limpiarFecha($primero->feclote),
                    'refrigerado' => $grupo->contains(fn ($r) => (int) $r->refrigerado > 0),
                    'requerido' => (int) $grupo->sum('cantidad'),
                    'pickeado' => (int) $grupo->sum(fn ($r) => max(0, (int) $r->cantdesp)),
                    'tocado' => $grupo->every(fn ($r) => (int) $r->cantdesp >= 0),
                    'reparto' => $grupo->map(fn ($r) => [
                        'numped' => (int) $r->numped,
                        // Un pedido puede traer el mismo producto en dos renglones.
                        'item' => (int) $r->item,
                        'nomcli' => (string) $r->nomcli,
                        'cantidad' => (int) $r->cantidad,
                        'asignado' => max(0, (int) $r->cantdesp),
                    ])->values()->all(),
                ];
            })
            ->sortBy([
                fn (array $a, array $b) => $a['tocado'] <=> $b['tocado'],
                fn (array $a, array $b) => strcmp($a[$campoOrden], $b[$campoOrden]),
                fn (array $a, array $b) => strcmp($a['vence'] ?: '9999', $b['vence'] ?: '9999'),
            ])
            ->values();
    }

    /** Registra lo recogido de un producto y lo reparte FIFO entre los pedidos. @return array el producto actualizado */
    public function registrarCantidad(SidesUsers $usuario, int $loteId, string $clave, int $cantidad): array
    {
        return DB::transaction(function () use ($usuario, $loteId, $clave, $cantidad) {
            $lote = $this->loteEnPickingDe($usuario, $loteId);

            $renglones = $this->renglonesDelLote($lote->id)->filter(fn ($r) => $this->claveProducto($r) === $clave)->values();
            if ($renglones->isEmpty()) {
                throw new BatchPickingException('Ese producto no está en el lote.');
            }

            $requerido = (int) $renglones->sum('cantidad');
            if ($cantidad < 0 || $cantidad > $requerido) {
                throw new BatchPickingException("La cantidad debe estar entre 0 y {$requerido}.");
            }

            // FIFO: los renglones vienen ordenados por antigüedad del pedido.
            $restante = $cantidad;
            foreach ($renglones as $renglon) {
                $asignado = min($restante, (int) $renglon->cantidad);
                SidesPedrenOperacion::query()
                    ->where('id_pedido', $renglon->numped)
                    ->where('item', $renglon->item)
                    ->update(['cantdesp' => $asignado, 'despachador' => $usuario->name]);
                $restante -= $asignado;
            }

            return $this->productos($lote->id, SidesCfg::query()->find($usuario->codisb))->firstWhere('clave', $clave);
        });
    }

    /** @return array{PACKING: int, PEND-FACTURA: int, ANULADO: int} pedidos por resultado */
    public function terminar(SidesUsers $usuario, int $loteId): array
    {
        return DB::transaction(function () use ($usuario, $loteId) {
            $lote = $this->loteEnPickingDe($usuario, $loteId);

            $pendientes = $this->productos($lote->id, null)->where('tocado', false)->count();
            if ($pendientes > 0) {
                throw new BatchPickingException($pendientes === 1
                    ? 'Falta 1 producto por marcar antes de terminar.'
                    : "Faltan {$pendientes} productos por marcar antes de terminar.");
            }

            $pedidoIds = SidesAlcabalaLotePedido::query()->where('id_lote', $lote->id)->pluck('numped')->all();
            Pedido::query()->whereKey($pedidoIds)->lockForUpdate()->get(['id']);

            // Lectura fresca: decide el estado siguiente de cada pedido.
            $cfg = SidesCfg::query()->find($usuario->codisb);
            $resultados = ['PACKING' => 0, 'PEND-FACTURA' => 0, 'ANULADO' => 0];
            foreach ($pedidoIds as $pedidoId) {
                $resultados[$this->picking->cerrarPedido((int) $pedidoId, $cfg)]++;
            }

            $lote->update(['estado' => 'TERMINADO', 'fecha_terminacion' => Carbon::now()]);

            return $resultados;
        });
    }

    /** El lote debe estar en picking y sus pedidos asignados a este usuario. */
    public function loteEnPickingDe(SidesUsers $usuario, int $loteId): SidesAlcabalaLote
    {
        $lote = $this->loteBloqueado($usuario, $loteId);

        if ($lote->estado !== 'CONFIRMADO') {
            throw new BatchPickingException("El lote #{$loteId} no está en picking.");
        }

        $ajeno = DB::table('sides_alcabala_lote_pedido as alp')
            ->leftJoin('sides_pedido_operacion as op', 'op.id_pedido', '=', 'alp.numped')
            ->where('alp.id_lote', $loteId)
            ->where(function ($consulta) use ($usuario) {
                $consulta->whereNull('op.despachador')
                    ->orWhere('op.despachador', '<>', $usuario->name)
                    ->orWhere('op.despasignado', '<>', 1);
            })
            ->first(['op.despachador']);

        if ($ajeno) {
            $quien = $ajeno->despachador ? " Lo está trabajando {$ajeno->despachador}." : '';
            throw new BatchPickingException("No tienes asignado el lote #{$loteId}.{$quien}");
        }

        return $lote;
    }

    private function loteBloqueado(SidesUsers $usuario, int $loteId): SidesAlcabalaLote
    {
        $lote = SidesAlcabalaLote::query()->where('codisb', $usuario->codisb)->whereKey($loteId)->lockForUpdate()->first();

        if (! $lote) {
            throw new BatchPickingException("No se encontró el lote #{$loteId} en tu sucursal.");
        }

        return $lote;
    }

    /** Renglones de todos los pedidos del lote, del pedido más antiguo al más nuevo. */
    private function renglonesDelLote(int $loteId): Collection
    {
        return DB::table('pedren')
            ->join('sides_pedren_operacion as op', function ($join) {
                $join->on('op.id_pedido', '=', 'pedren.id')->on('op.item', '=', 'pedren.item');
            })
            ->join('sides_alcabala_lote_pedido as alp', 'alp.numped', '=', 'pedren.id')
            ->join('pedido', 'pedido.id', '=', 'pedren.id')
            ->where('alp.id_lote', $loteId)
            ->orderBy('pedido.fecha')
            ->orderBy('pedido.id')
            ->orderBy('pedren.item')
            ->get([
                'pedren.id as numped', 'pedren.item', 'pedren.codprod', 'pedren.desprod', 'pedren.barra',
                'pedren.cantidad', 'pedren.marcamodelo', 'pedren.refrigerado', 'op.cantdesp', 'pedido.nomcli',
                DB::raw("COALESCE(NULLIF(op.ubicacion, ''), pedren.ubicacion) as ubicacion"),
                DB::raw("COALESCE(NULLIF(op.lote, ''), pedren.lote) as lote"),
                DB::raw("COALESCE(NULLIF(op.feclote, ''), pedren.feclote) as feclote"),
            ]);
    }

    private function claveProducto(object $renglon): string
    {
        return $renglon->codprod.'|'.(string) $renglon->lote;
    }

    /** @return list<int> */
    private function limpiarIds(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id) => $id > 0)));
    }
}
