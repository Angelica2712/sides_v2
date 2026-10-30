<?php

namespace App\Services\Packing;

use App\Models\Seped\Pedido;
use App\Models\Sides\SidesRetencion;
use App\Models\Sides\SidesRetencionRen;
use App\Models\Sides\SidesUsers;
use App\Services\Despacho\DespachoSeped;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Alcabala de packing: pedidos de un cliente que terminaron el packing y se retienen por si llega
 * otro del mismo cliente, para sumárselo y que salgan juntos.
 *
 * El pedido retenido sigue en PACKING: SEPED conserva sus unidades comprometidas y el SIAD no lo
 * baja, sin ningún estado nuevo que las dos apps tengan que conocer. Lo que lo distingue es su fila
 * abierta en sides_retencion_ren (fec_liberado nulo). Al liberarlo pasa a PEND-FACTURA y recién ahí
 * se escribe el despacho en pedren, igual que al terminar un packing normal.
 *
 * Los que se liberan juntos se funden en el de número más bajo y el ERP recibe un solo pedido: sus
 * renglones pasan a ese pedido (pedren.item es único en toda la tabla) sin sumarse, porque cada uno
 * trae su precio y sus descuentos. Los demás quedan ANULADO, sin renglones y sin comprometer unidades,
 * con la observación de a dónde fueron; sides_pedido_union guarda de dónde salió cada renglón.
 */
class RetencionService
{
    /** Estados en los que otro pedido del cliente todavía puede llegar a esta alcabala. */
    public const ESTADOS_EN_CAMINO = ['ALCABALA', 'RECIBIDO', 'PICKING', 'PACKING'];

    /** Condiciones comerciales de la cabecera: si difieren, una sola factura cobraría mal alguno de los pedidos. */
    private const DEBEN_COINCIDIR = ['dcredito' => 'días de crédito', 'pedfiscal' => 'tipo fiscal', 'di' => 'descuento', 'dc' => 'descuento',
        'pp' => 'pronto pago', 'factorcambiario' => 'tasa de cambio', 'codvend' => 'vendedor'];

    /** Importes de la cabecera que se suman al fundir. */
    private const SE_SUMAN = ['subrenglon', 'descuento', 'subtotal', 'impuesto', 'total', 'numren', 'numund'];

    public function __construct(private readonly DespachoSeped $despachoSeped)
    {
    }

    /**
     * Ids de los pedidos retenidos de la sucursal. De paso saca los que ya no están en PACKING: los
     * pudo mover SEPED al devolverlos a su alcabala, o el SIAD.
     *
     * @return list<int>
     */
    public function retenidos(string $codisb): array
    {
        $filas = $this->renglonesAbiertos($codisb)
            ->join('pedido', 'pedido.id', '=', 'sides_retencion_ren.id_pedido')
            ->get(['sides_retencion_ren.id', 'sides_retencion_ren.id_retencion', 'sides_retencion_ren.id_pedido', 'pedido.estado']);

        $huerfanos = $filas->where('estado', '!=', 'PACKING');
        if ($huerfanos->isNotEmpty()) {
            SidesRetencionRen::query()->whereKey($huerfanos->pluck('id')->all())->delete();
            $huerfanos->pluck('id_retencion')->unique()->each(fn ($id) => $this->cerrarSiQuedoVacia((int) $id, 'SISTEMA'));
        }

        return $filas->where('estado', 'PACKING')->pluck('id_pedido')->map(fn ($id) => (int) $id)->values()->all();
    }

    public function estaRetenido(int $pedidoId): bool
    {
        return SidesRetencionRen::query()->where('id_pedido', $pedidoId)->whereNull('fec_liberado')->exists();
    }

    /** Retención abierta del cliente, si tiene: a ella se anexa el próximo pedido que se retenga. */
    public function abiertaDelCliente(string $codisb, string $codcli): ?SidesRetencion
    {
        return SidesRetencion::query()
            ->where('codisb', $codisb)->where('codcli', $codcli)->where('estado', 'ABIERTA')
            ->whereHas('renglones', fn ($q) => $q->whereNull('fec_liberado'))
            ->first();
    }

    /** Lo llama PackingService dentro de su transacción. Anexa a la retención del cliente o abre una. */
    public function retener(SidesUsers $usuario, Pedido $pedido): SidesRetencion
    {
        $ahora = Carbon::now();

        $retencion = SidesRetencion::query()
            ->where('codisb', $pedido->codisb)->where('codcli', $pedido->codcli)->where('estado', 'ABIERTA')
            ->lockForUpdate()->first()
            ?? SidesRetencion::query()->create([
                'codisb' => $pedido->codisb,
                'codcli' => $pedido->codcli,
                'nomcli' => mb_substr((string) $pedido->nomcli, 0, 150),
                'estado' => 'ABIERTA',
                'usuario' => $usuario->email,
                'fecha' => $ahora,
            ]);

        SidesRetencionRen::query()->create([
            'id_retencion' => $retencion->id,
            'id_pedido' => $pedido->id,
            'usuario' => $usuario->email,
            'fecha' => $ahora,
        ]);

        return $retencion;
    }

    /**
     * Retenciones abiertas con sus pedidos y los otros pedidos del cliente que vienen en camino.
     *
     * @return Collection<int, SidesRetencion>
     */
    public function listar(string $codisb): Collection
    {
        $retenidos = $this->retenidos($codisb);
        if ($retenidos === []) {
            return collect();
        }

        $renglones = SidesRetencionRen::query()->whereIn('id_pedido', $retenidos)->whereNull('fec_liberado')->get()->keyBy('id_pedido');
        $pedidos = Pedido::query()
            ->leftJoin('sides_pedido_operacion as op', 'op.id_pedido', '=', 'pedido.id')
            ->whereIn('pedido.id', $retenidos)
            ->orderBy('pedido.id')
            ->get(['pedido.id', 'pedido.ruta', 'pedido.numren', 'pedido.numund', 'op.cantBultos', 'op.embalador'])
            ->map(function (Pedido $pedido) use ($renglones) {
                $pedido->id_retencion = (int) $renglones->get($pedido->id)->id_retencion;
                $pedido->retenido_desde = $renglones->get($pedido->id)->fecha;

                return $pedido;
            });

        $retenciones = SidesRetencion::query()->whereKey($pedidos->pluck('id_retencion')->unique()->all())->orderBy('fecha')->get();
        $enCamino = Pedido::query()
            ->where('codisb', $codisb)
            ->whereIn('codcli', $retenciones->pluck('codcli')->all())
            ->whereIn('estado', self::ESTADOS_EN_CAMINO)
            ->whereNotIn('id', $retenidos)
            ->orderBy('id')
            ->get(['id', 'codcli', 'estado'])
            ->groupBy('codcli');

        return $retenciones->map(function (SidesRetencion $retencion) use ($pedidos, $enCamino) {
            $retencion->pedidos = $pedidos->where('id_retencion', $retencion->id)->values();
            $retencion->enCamino = $enCamino->get($retencion->codcli, collect());

            return $retencion;
        });
    }

    /**
     * Libera a facturar los pedidos de la retención. Con $pedidoId sale solo ese y los demás siguen
     * retenidos; si salen dos o más a la vez se funden en uno.
     *
     * @param  array<int|string, string|null>  $bultos  bultos por pedido: al anexar suelen cambiar
     * @return array{pedido: int, unidos: list<int>} el pedido que sale a facturar y los que se fundieron en él
     */
    public function liberar(SidesUsers $usuario, int $retencionId, array $bultos = [], ?int $pedidoId = null): array
    {
        return DB::transaction(function () use ($usuario, $retencionId, $bultos, $pedidoId) {
            $retencion = SidesRetencion::query()->where('codisb', $usuario->codisb)->whereKey($retencionId)->lockForUpdate()->first();
            if (! $retencion || $retencion->estado !== 'ABIERTA') {
                throw new PackingException('Esa retención ya no está abierta.');
            }

            $renglones = SidesRetencionRen::query()->where('id_retencion', $retencion->id)->whereNull('fec_liberado')
                ->when($pedidoId !== null, fn ($q) => $q->where('id_pedido', $pedidoId))
                ->get();
            $pedidos = Pedido::query()->whereIn('id', $renglones->pluck('id_pedido')->all())->where('estado', 'PACKING')->orderBy('id')->lockForUpdate()->get();
            if ($pedidos->isEmpty()) {
                throw new PackingException('Esos pedidos ya no están retenidos.');
            }

            $this->exigirMismasCondiciones($pedidos);

            $ahora = Carbon::now();
            $ids = $pedidos->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
            $final = $pedidos->first();
            $unidos = $pedidos->slice(1)->values();
            $totalBultos = $this->totalBultos($ids, $bultos);

            foreach ($unidos as $unido) {
                $this->fundir($final, $unido, $usuario, $ahora);
            }

            Pedido::query()->whereKey($final->id)->update(['estado' => 'PEND-FACTURA', 'feccompletado' => $ahora]);
            $this->despachoSeped->escribir($final->id);

            if ($totalBultos > 0) {
                DB::table('sides_pedido_operacion')->where('id_pedido', $final->id)->update(['cantBultos' => (string) $totalBultos]);
            }

            SidesRetencionRen::query()->where('id_retencion', $retencion->id)->whereIn('id_pedido', $ids)->whereNull('fec_liberado')->update(['fec_liberado' => $ahora]);

            $this->cerrarSiQuedoVacia($retencion->id, $usuario->email);

            return ['pedido' => (int) $final->id, 'unidos' => $unidos->pluck('id')->map(fn ($id) => (int) $id)->all()];
        });
    }

    /** El pedido sale de la retención porque lo resetearon, anularon o le cambiaron el estado a mano. */
    public function sacar(int $pedidoId, string $usuario): void
    {
        $renglones = SidesRetencionRen::query()->where('id_pedido', $pedidoId)->whereNull('fec_liberado')->get();
        foreach ($renglones as $renglon) {
            $renglon->delete();
            $this->cerrarSiQuedoVacia((int) $renglon->id_retencion, $usuario);
        }
    }

    /** @param  Collection<int, Pedido>  $pedidos */
    private function exigirMismasCondiciones(Collection $pedidos): void
    {
        $primero = $pedidos->first();
        foreach ($pedidos->slice(1) as $pedido) {
            foreach (self::DEBEN_COINCIDIR as $campo => $nombre) {
                $iguales = is_numeric($pedido->{$campo}) && is_numeric($primero->{$campo})
                    ? (float) $pedido->{$campo} === (float) $primero->{$campo}
                    : (string) $pedido->{$campo} === (string) $primero->{$campo};

                if (! $iguales) {
                    throw new PackingException("Los pedidos #{$primero->id} y #{$pedido->id} no se pueden unir: tienen distinto {$nombre}. Envíalos a facturar uno por uno.");
                }
            }
        }
    }

    /**
     * Bultos del pedido fundido: los que escribieron al liberar o, si no, los de su packing.
     *
     * @param  list<int>  $ids
     */
    private function totalBultos(array $ids, array $bultos): int
    {
        $guardados = DB::table('sides_pedido_operacion')->whereIn('id_pedido', $ids)->pluck('cantBultos', 'id_pedido');

        return (int) collect($ids)->sum(fn (int $id) => (int) (trim((string) ($bultos[$id] ?? '')) ?: $guardados->get($id)));
    }

    /** Pasa los renglones de $unido a $final, suma la cabecera y deja a $unido anulado con la referencia. */
    private function fundir(Pedido $final, Pedido $unido, SidesUsers $usuario, Carbon $ahora): void
    {
        DB::table('sides_pedido_union')->insert([
            'codisb' => $final->codisb,
            'id_pedido' => $final->id,
            'id_unido' => $unido->id,
            'items' => json_encode(DB::table('pedren')->where('id', $unido->id)->pluck('item')->map(fn ($item) => (int) $item)->all()),
            'usuario' => $usuario->email,
            'fecha' => $ahora,
        ]);

        DB::table('pedren')->where('id', $unido->id)->update(['id' => $final->id]);
        DB::table('sides_pedren_operacion')->where('id_pedido', $unido->id)->update(['id_pedido' => $final->id]);

        Pedido::query()->whereKey($final->id)->update(collect(self::SE_SUMAN)
            ->mapWithKeys(fn (string $campo) => [$campo => DB::raw("COALESCE({$campo}, 0) + ".(float) $unido->{$campo})])->all());

        Pedido::query()->whereKey($unido->id)->update([
            'estado' => 'ANULADO',
            'observacion' => mb_substr(trim("Unido al pedido #{$final->id}. {$unido->observacion}"), 0, 500),
        ]);
    }

    private function cerrarSiQuedoVacia(int $retencionId, string $usuario): void
    {
        if (SidesRetencionRen::query()->where('id_retencion', $retencionId)->whereNull('fec_liberado')->exists()) {
            return;
        }

        SidesRetencion::query()->whereKey($retencionId)->where('estado', 'ABIERTA')->update([
            'estado' => 'LIBERADA',
            'usuario_liberado' => $usuario,
            'fec_liberada' => Carbon::now(),
        ]);
    }

    private function renglonesAbiertos(string $codisb)
    {
        return SidesRetencionRen::query()
            ->join('sides_retencion as r', 'r.id', '=', 'sides_retencion_ren.id_retencion')
            ->where('r.codisb', $codisb)
            ->where('r.estado', 'ABIERTA')
            ->whereNull('sides_retencion_ren.fec_liberado');
    }
}
