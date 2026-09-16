<?php

namespace App\Services\Guias;

use App\Models\Seped\Choferes;
use App\Models\Sides\SidesGuia;
use App\Models\Sides\SidesGuiaRen;
use App\Models\Sides\SidesRuta;
use App\Models\Sides\SidesRutaren;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Guías de despacho (legacy AdminguiasController, AdminguiasrenController y
 * AdminguiachoferController, idénticos en los tres SIDES).
 *
 * Una guía lleva los bultos (sides_etiqueta_pedido) de pedidos FACTURADO de los clientes de una
 * ruta. Cada bulto pasa por EN GUIA → CARGADO (el chofer lo sube al camión) → ENTREGADO. El
 * estado del cliente en la guía (sides_guia_ren.cargado/terminado) y el de la guía se recalculan
 * siempre a partir de sus bultos, en vez de fijarse a mano en cada pantalla como el legacy.
 *
 * No se portan el orden por "mejor ruta" ni el mapa: dependían de las coordenadas de la base
 * externa de iCompras360 (direcciones_despacho), que SIDES v2 no tiene.
 */
class GuiasService
{
    public const ESTADOS = ['NUEVO', 'CARGANDO', 'CARGADO', 'TRANSITO', 'ENTREGADO'];

    public const POR_PAGINA = 50;

    /** Motivos de reclamo que el legacy contaba como devolución en el Excel de la guía. */
    public const MOTIVOS_DEVOLUCION = ['MAL ESTADO', 'NO SOLICITADO', 'RETIRO DEL MERCADO', 'SOBRANTE', 'VENCIMIENTO'];

    /** @param array{fecha: string, estado: string, ruta: string, chofer: string} $filtros */
    public function listar(string $codisb, array $filtros): LengthAwarePaginator
    {
        return SidesGuia::query()
            ->where('codisb', $codisb)
            ->when($filtros['fecha'] !== '', fn ($q) => $q->whereBetween('fecha', [$filtros['fecha'].' 00:00:00', $filtros['fecha'].' 23:59:59']))
            ->when($filtros['estado'] !== '', fn ($q) => $q->where('estado', $filtros['estado']))
            ->when($filtros['ruta'] !== '', fn ($q) => $q->where('ruta', $filtros['ruta']))
            ->when($filtros['chofer'] !== '', fn ($q) => $q->where(fn ($q) => $q->where('chofer', $filtros['chofer'])->orWhere('chofer_aux_id', $filtros['chofer'])))
            ->select('sides_guia.*')
            ->selectSub(fn ($q) => $q->from('sides_guia_ren')->whereColumn('sides_guia_ren.id', 'sides_guia.id')->selectRaw('COUNT(*)'), 'clientes')
            ->selectSub(fn ($q) => $q->from('sides_etiqueta_pedido')->whereColumn('sides_etiqueta_pedido.guia', 'sides_guia.id')->selectRaw('COUNT(*)'), 'bultos')
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->paginate(self::POR_PAGINA)
            ->withQueryString();
    }

    /** @return array{rutas: Collection, choferes: Collection} valores usados en las guías de la sucursal */
    public function opcionesFiltro(string $codisb): array
    {
        $guias = SidesGuia::query()->where('codisb', $codisb);

        return [
            'rutas' => (clone $guias)->distinct()->orderBy('ruta')->pluck('ruta'),
            'choferes' => (clone $guias)->distinct()->orderBy('nomchofer')->pluck('nomchofer', 'chofer'),
        ];
    }

    /** Choferes y auxiliares de la sucursal (tabla `choferes` de SEPED). */
    public function choferes(string $codisb): Collection
    {
        return Choferes::query()->where('codisb', $codisb)->orderBy('chof_nom')->get()->unique('chof_co')->values();
    }

    public function buscar(string $codisb, int $id): SidesGuia
    {
        return SidesGuia::query()->where('codisb', $codisb)->find($id)
            ?? throw new GuiasException("La guía #{$id} no existe en tu sucursal.");
    }

    /** Crea la guía de una ruta con los pedidos facturados (y etiquetados) de sus clientes. */
    public function crear(string $codisb, string $fecha, string $chofer, int $rutaId): SidesGuia
    {
        $ruta = SidesRuta::query()->where('codisb', $codisb)->find($rutaId)
            ?? throw new GuiasException('Elige una ruta de la lista.');
        $datosChofer = $this->chofer($codisb, $chofer);

        // Los clientes que retiran en local no van en la guía.
        $clientes = SidesRutaren::query()->where('id', $ruta->id)->where('retiraLocal', 0)->get()->keyBy('codcli');
        $pedidos = $this->pedidosPendientes($codisb, $clientes->keys()->all());
        if ($pedidos->isEmpty()) {
            throw new GuiasException("La ruta {$ruta->nombre} no tiene pedidos facturados con etiquetas pendientes de despacho.");
        }

        return DB::transaction(function () use ($codisb, $fecha, $datosChofer, $ruta, $clientes, $pedidos) {
            $guia = SidesGuia::query()->create([
                'fecha' => Carbon::parse($fecha),
                'chofer' => $datosChofer->chof_co,
                'nomchofer' => (string) $datosChofer->chof_nom,
                'codisb' => $codisb,
                'estado' => 'NUEVO',
                'ruta' => $ruta->nombre,
            ]);

            $this->asignarPedidos($guia, $pedidos, $clientes);

            return $guia;
        });
    }

    /**
     * Pedidos FACTURADO de la sucursal con bultos sin guía.
     *
     * @param  list<string>|null  $clientes  limita a estos clientes
     * @return Collection<int, object{id: int, codcli: string, nomcli: string, fecfacturado: ?string, numren: int, numund: int, bultos: int}>
     */
    public function pedidosPendientes(string $codisb, ?array $clientes = null): Collection
    {
        if ($clientes === []) {
            return collect();
        }

        return DB::table('pedido')
            ->join('sides_etiqueta_pedido as e', 'e.numepedi', '=', 'pedido.id')
            ->where('pedido.codisb', $codisb)
            ->where('pedido.estado', 'FACTURADO')
            ->where('e.estado', 'NUEVO')
            ->whereNull('e.guia')
            ->when($clientes !== null, fn ($q) => $q->whereIn('pedido.codcli', $clientes))
            ->groupBy('pedido.id')
            ->selectRaw('pedido.id, MAX(pedido.codcli) as codcli, MAX(pedido.nomcli) as nomcli, MAX(pedido.fecfacturado) as fecfacturado, MAX(pedido.numren) as numren, MAX(pedido.numund) as numund, COUNT(e.id) as bultos')
            ->orderBy('nomcli')
            ->orderBy('pedido.id')
            ->get();
    }

    /**
     * Clientes de la guía, en orden de visita, con sus pedidos y bultos.
     *
     * @return Collection<int, object> cada cliente: codcli, nomcli, orden, cargado, terminado, pedidos (Collection)
     */
    public function clientes(SidesGuia $guia): Collection
    {
        $bultos = DB::table('sides_etiqueta_pedido')->where('guia', $guia->id)->orderBy('numepedi')->orderBy('etiqueta')->get();
        $facturas = $this->facturas($guia->codisb, $bultos->pluck('numepedi')->unique()->all());
        $pedidos = DB::table('pedido')->whereIn('id', $bultos->pluck('numepedi')->unique()->all())
            ->get(['id', 'fecfacturado', 'observacion', 'numren', 'numund', 'tipedido'])->keyBy('id');

        return SidesGuiaRen::query()->where('id', $guia->id)->orderBy('orden')->get()
            ->map(function (SidesGuiaRen $ren) use ($bultos, $pedidos, $facturas) {
                $ren->pedidos = $bultos->where('codcli', $ren->codcli)->groupBy('numepedi')
                    ->map(fn (Collection $etiquetas, $numero) => (object) [
                        'id' => (int) $numero,
                        'datos' => $pedidos->get((int) $numero),
                        'facturas' => $facturas->get((int) $numero, collect()),
                        'bultos' => $etiquetas->values(),
                    ])->values();
                $ren->totalBultos = $ren->pedidos->sum(fn ($p) => $p->bultos->count());

                return $ren;
            });
    }

    /** Pedidos pendientes de los clientes de la ruta de la guía, para agregarlos. */
    public function pedidosParaAgregar(SidesGuia $guia): Collection
    {
        $clientes = SidesRutaren::query()
            ->join('sides_rutas as r', 'r.id', '=', 'sides_rutasren.id')
            ->where('r.codisb', $guia->codisb)
            ->where('r.nombre', $guia->ruta)
            ->pluck('sides_rutasren.codcli')
            ->merge(SidesGuiaRen::query()->where('id', $guia->id)->pluck('codcli'))
            ->unique()->values()->all();

        return $this->pedidosPendientes($guia->codisb, $clientes);
    }

    /** @param list<int> $pedidoIds */
    public function agregarPedidos(SidesGuia $guia, array $pedidoIds): int
    {
        $pedidos = $this->pedidosPendientes($guia->codisb)->whereIn('id', array_map('intval', $pedidoIds));
        if ($pedidos->isEmpty()) {
            throw new GuiasException('Esos pedidos ya no están pendientes de despacho.');
        }

        $clientes = SidesRutaren::query()
            ->join('sides_rutas as r', 'r.id', '=', 'sides_rutasren.id')
            ->where('r.codisb', $guia->codisb)
            ->where('r.nombre', $guia->ruta)
            ->get(['sides_rutasren.*'])
            ->keyBy('codcli');

        DB::transaction(fn () => $this->asignarPedidos($guia, $pedidos, $clientes));

        return $pedidos->count();
    }

    public function quitarPedido(SidesGuia $guia, int $pedidoId): void
    {
        DB::transaction(function () use ($guia, $pedidoId) {
            $bultos = DB::table('sides_etiqueta_pedido')->where('guia', $guia->id)->where('numepedi', (string) $pedidoId);
            $codcli = (clone $bultos)->value('codcli') ?? throw new GuiasException("El pedido #{$pedidoId} no está en la guía.");
            if ((clone $bultos)->whereNotNull('fecentregado')->exists()) {
                throw new GuiasException("El pedido #{$pedidoId} ya tiene bultos entregados: no se puede quitar de la guía.");
            }

            $this->liberarBultos($bultos);
            if (! DB::table('sides_etiqueta_pedido')->where('guia', $guia->id)->where('codcli', $codcli)->exists()) {
                SidesGuiaRen::query()->where('id', $guia->id)->where('codcli', $codcli)->delete();
            }
            $this->recalcular($guia);
        });
    }

    public function quitarCliente(SidesGuia $guia, string $codcli): void
    {
        DB::transaction(function () use ($guia, $codcli) {
            $bultos = DB::table('sides_etiqueta_pedido')->where('guia', $guia->id)->where('codcli', $codcli);
            if ((clone $bultos)->whereNotNull('fecentregado')->exists()) {
                throw new GuiasException('El cliente ya tiene bultos entregados: no se puede quitar de la guía.');
            }

            $this->liberarBultos($bultos);
            SidesGuiaRen::query()->where('id', $guia->id)->where('codcli', $codcli)->delete();
            $this->recalcular($guia);
        });
    }

    /** Marca como cargado un bulto (código escaneado). Devuelve el bulto. */
    public function cargar(SidesGuia $guia, string $codigo): object
    {
        return DB::transaction(function () use ($guia, $codigo) {
            $bulto = $this->bulto($guia, $codigo);
            if ($bulto->estado !== 'EN GUIA') {
                throw new GuiasException("El bulto {$bulto->etiqueta} ya está ".mb_strtolower($bulto->estado).'.');
            }

            DB::table('sides_etiqueta_pedido')->where('id', $bulto->id)->update(['estado' => 'CARGADO', 'feccargado' => Carbon::now()]);
            $this->recalcular($guia);

            return $bulto;
        });
    }

    /**
     * Marca bultos como entregados: uno (código) o todos los de un cliente.
     *
     * @param  bool  $exigirCargado  el chofer solo entrega lo que subió al camión
     * @return int bultos entregados
     */
    public function entregar(SidesGuia $guia, ?string $codigo, ?string $codcli, bool $exigirCargado): int
    {
        return DB::transaction(function () use ($guia, $codigo, $codcli, $exigirCargado) {
            $bultos = DB::table('sides_etiqueta_pedido')->where('guia', $guia->id)->whereNull('fecentregado');

            if ($codigo !== null) {
                $bulto = $this->bulto($guia, $codigo);
                if ($bulto->estado === 'ENTREGADO') {
                    throw new GuiasException("El bulto {$bulto->etiqueta} ya fue entregado.");
                }
                if ($exigirCargado && $bulto->estado !== 'CARGADO') {
                    throw new GuiasException("El bulto {$bulto->etiqueta} no se cargó en el camión.");
                }
                $bultos->where('id', $bulto->id);
            } else {
                $bultos->where('codcli', (string) $codcli)->when($exigirCargado, fn ($q) => $q->where('estado', 'CARGADO'));
            }

            $ahora = Carbon::now();
            $cantidad = (clone $bultos)->count();
            if ($cantidad === 0) {
                throw new GuiasException('El cliente no tiene bultos pendientes de entrega.');
            }
            (clone $bultos)->whereNull('feccargado')->update(['feccargado' => $ahora]);
            $bultos->update(['estado' => 'ENTREGADO', 'fecentregado' => $ahora]);
            $this->recalcular($guia);

            return $cantidad;
        });
    }

    /** Devuelve un bulto a EN GUIA (deshace carga y entrega). */
    public function reiniciar(SidesGuia $guia, string $codigo): void
    {
        DB::transaction(function () use ($guia, $codigo) {
            $bulto = $this->bulto($guia, $codigo);
            DB::table('sides_etiqueta_pedido')->where('id', $bulto->id)->update(['estado' => 'EN GUIA', 'feccargado' => null, 'fecentregado' => null]);
            $this->recalcular($guia);
        });
    }

    /** @param array{fecha: string, chofer: string, auxiliar: ?string, fecha_salida: ?string, unidad: ?string} $datos */
    public function actualizar(SidesGuia $guia, array $datos): void
    {
        $chofer = $this->chofer($guia->codisb, $datos['chofer']);
        $auxiliar = filled($datos['auxiliar']) ? $this->chofer($guia->codisb, $datos['auxiliar']) : null;
        if ($auxiliar && $auxiliar->chof_co === $chofer->chof_co) {
            throw new GuiasException('El auxiliar no puede ser el mismo chofer.');
        }

        $guia->update([
            'fecha' => Carbon::parse($datos['fecha']),
            'chofer' => $chofer->chof_co,
            'nomchofer' => (string) $chofer->chof_nom,
            'chofer_aux_id' => $auxiliar?->chof_co,
            'chof_aux_nom' => $auxiliar?->chof_nom,
            'fecha_salida' => filled($datos['fecha_salida']) ? Carbon::parse($datos['fecha_salida']) : null,
            'unidad' => filled($datos['unidad']) ? trim($datos['unidad']) : null,
        ]);
    }

    public function eliminar(SidesGuia $guia): void
    {
        DB::transaction(function () use ($guia) {
            $bultos = DB::table('sides_etiqueta_pedido')->where('guia', $guia->id);
            if ((clone $bultos)->whereNotNull('fecentregado')->exists()) {
                throw new GuiasException('No se puede eliminar una guía con bultos entregados.');
            }

            $this->liberarBultos($bultos);
            SidesGuiaRen::query()->where('id', $guia->id)->delete();
            $guia->delete();
        });
    }

    /**
     * Pasa algunos clientes a una guía nueva con otro chofer (legacy separarGuia).
     *
     * @param  list<string>  $clientes
     */
    public function separar(SidesGuia $guia, array $clientes, string $chofer): SidesGuia
    {
        $enGuia = SidesGuiaRen::query()->where('id', $guia->id)->pluck('codcli');
        $clientes = $enGuia->intersect($clientes)->values();
        if ($clientes->isEmpty()) {
            throw new GuiasException('Marca los clientes que pasan a la guía nueva.');
        }
        if ($clientes->count() === $enGuia->count()) {
            throw new GuiasException('Deja al menos un cliente en esta guía. Para mover todos, cambia el chofer de la guía.');
        }
        $datosChofer = $this->chofer($guia->codisb, $chofer);

        return DB::transaction(function () use ($guia, $clientes, $datosChofer) {
            $nueva = SidesGuia::query()->create([
                'fecha' => Carbon::now(),
                'chofer' => $datosChofer->chof_co,
                'nomchofer' => (string) $datosChofer->chof_nom,
                'codisb' => $guia->codisb,
                'estado' => 'NUEVO',
                'ruta' => $guia->ruta,
                'unidad' => null,
            ]);

            SidesGuiaRen::query()->where('id', $guia->id)->whereIn('codcli', $clientes->all())->update(['id' => $nueva->id]);
            DB::table('sides_etiqueta_pedido')->where('guia', $guia->id)->whereIn('codcli', $clientes->all())->update(['guia' => $nueva->id]);
            $this->recalcular($guia);
            $this->recalcular($nueva);

            return $nueva;
        });
    }

    /**
     * Estado de cada cliente y de la guía a partir de sus bultos:
     * NUEVO → CARGANDO → CARGADO → TRANSITO (algo entregado) → ENTREGADO (todo entregado).
     */
    public function recalcular(SidesGuia $guia): void
    {
        $bultos = DB::table('sides_etiqueta_pedido')->where('guia', $guia->id)->get(['codcli', 'feccargado', 'fecentregado']);

        foreach ($bultos->groupBy('codcli') as $codcli => $delCliente) {
            SidesGuiaRen::query()->where('id', $guia->id)->where('codcli', $codcli)->update([
                'cargado' => $delCliente->every(fn ($b) => $b->feccargado !== null) ? 1 : 0,
                'terminado' => $delCliente->every(fn ($b) => $b->fecentregado !== null) ? 1 : 0,
            ]);
        }

        $entregados = $bultos->whereNotNull('fecentregado')->count();
        $cargados = $bultos->whereNotNull('feccargado')->count();
        $estado = match (true) {
            $bultos->isNotEmpty() && $entregados === $bultos->count() => 'ENTREGADO',
            $entregados > 0 => 'TRANSITO',
            $bultos->isNotEmpty() && $cargados === $bultos->count() => 'CARGADO',
            $cargados > 0 => 'CARGANDO',
            default => 'NUEVO',
        };
        $guia->update(['estado' => $estado]);
    }

    /**
     * Facturas de SEPED de los pedidos, por número de pedido. SEPED guarda el pedido dentro de
     * fact.descrip (primer número del texto), como leía el legacy con REGEXP_SUBSTR.
     *
     * @param  list<int|string>  $pedidos
     * @return Collection<int, Collection<int, object{factnum: string, nroctrol: string, codcli: string}>>
     */
    public function facturas(string $codisb, array $pedidos): Collection
    {
        $pedidos = array_values(array_unique(array_map('intval', $pedidos)));
        if ($pedidos === []) {
            return collect();
        }

        return collect($pedidos)->chunk(200)->flatMap(fn (Collection $lote) => DB::table('fact')
            ->where('codisb', $codisb)
            ->where(fn ($q) => $lote->each(fn ($id) => $q->orWhere('descrip', 'like', "%{$id}%")))
            ->get(['factnum', 'nroctrol', 'codcli', 'descrip']))
            ->map(function ($factura) {
                $factura->pedido = preg_match('/\d+/', (string) $factura->descrip, $m) ? (int) $m[0] : null;

                return $factura;
            })
            ->filter(fn ($factura) => in_array($factura->pedido, $pedidos, true))
            ->unique('factnum')
            ->groupBy('pedido');
    }

    /** Datos de SEPED de los clientes (dirección de entrega). */
    public function datosClientes(string $codisb, array $codigos): Collection
    {
        return DB::table('cliente')->where('codisb', $codisb)->whereIn('codcli', $codigos)
            ->get(['codcli', 'nombre', 'rif', 'direccion', 'entrega'])->keyBy('codcli');
    }

    /**
     * Notas de crédito (cxc) y devoluciones (renglones de reclamo con motivo de devolución) de las
     * facturas de la guía, por cliente. Columnas NOTAS y DEV del Excel legacy.
     *
     * @param  list<string>  $facturas
     * @return array{notas: Collection<string, int>, devoluciones: Collection<string, int>}
     */
    public function notasYDevoluciones(string $codisb, array $facturas): array
    {
        if ($facturas === []) {
            return ['notas' => collect(), 'devoluciones' => collect()];
        }

        $notas = DB::table('cxc')->where('codisb', $codisb)->whereIn('id', $facturas)
            ->selectRaw('codcli, COUNT(*) as total')->groupBy('codcli')->pluck('total', 'codcli')->map(fn ($n) => (int) $n);

        $devoluciones = DB::table('reclamo')
            ->join('recren', 'recren.id', '=', 'reclamo.id')
            ->where('reclamo.codisb', $codisb)
            ->whereIn('reclamo.factnum', $facturas)
            ->whereIn(DB::raw('TRIM(recren.motivo)'), self::MOTIVOS_DEVOLUCION)
            ->selectRaw('reclamo.codcli, COUNT(*) as total')->groupBy('reclamo.codcli')->pluck('total', 'codcli')->map(fn ($n) => (int) $n);

        return ['notas' => $notas, 'devoluciones' => $devoluciones];
    }

    private function chofer(string $codisb, string $codigo): Choferes
    {
        return Choferes::query()->where('codisb', $codisb)->where('chof_co', $codigo)->first()
            ?? throw new GuiasException('Elige un chofer de la lista.');
    }

    /** Bulto de la guía por su código impreso (ej. 12345-01). */
    private function bulto(SidesGuia $guia, string $codigo): object
    {
        $codigo = trim($codigo);

        return DB::table('sides_etiqueta_pedido')->where('guia', $guia->id)->where('etiqueta', $codigo)->lockForUpdate()->first()
            ?? throw new GuiasException("El bulto {$codigo} no está en la guía #{$guia->id}.");
    }

    /**
     * Pone los bultos de los pedidos en la guía y agrega sus clientes en el orden de la ruta.
     *
     * @param  Collection<int, object>  $pedidos  de pedidosPendientes()
     * @param  Collection<string, SidesRutaren>  $clientesRuta
     */
    private function asignarPedidos(SidesGuia $guia, Collection $pedidos, Collection $clientesRuta): void
    {
        DB::table('sides_etiqueta_pedido')
            ->whereIn('numepedi', $pedidos->pluck('id')->map(fn ($id) => (string) $id)->all())
            ->where('estado', 'NUEVO')
            ->whereNull('guia')
            ->update(['guia' => $guia->id, 'estado' => 'EN GUIA', 'feccargado' => null, 'fecentregado' => null]);

        $enGuia = SidesGuiaRen::query()->where('id', $guia->id)->pluck('codcli')->all();
        // Un cliente que no está en la ruta va después del último.
        $ultimo = max(
            (int) SidesGuiaRen::query()->where('id', $guia->id)->max('orden'),
            (int) $clientesRuta->max(fn ($cliente) => (int) $cliente->sec),
        );
        $nuevos = $pedidos->unique('codcli')->reject(fn ($p) => in_array($p->codcli, $enGuia, true))
            ->map(function ($pedido) use ($guia, $clientesRuta, &$ultimo) {
                $enRuta = $clientesRuta->get($pedido->codcli);

                return [
                    'id' => $guia->id,
                    'codcli' => $pedido->codcli,
                    'nomcli' => mb_substr((string) ($enRuta?->nomcli ?: $pedido->nomcli), 0, 100),
                    'orden' => $enRuta ? (int) $enRuta->sec : ($ultimo += 20),
                    'cargado' => 0,
                    'terminado' => 0,
                ];
            });
        SidesGuiaRen::query()->insert($nuevos->values()->all());

        $this->recalcular($guia);
    }

    private function liberarBultos($bultos): void
    {
        (clone $bultos)->update(['estado' => 'NUEVO', 'guia' => null, 'feccargado' => null, 'fecentregado' => null]);
    }
}
