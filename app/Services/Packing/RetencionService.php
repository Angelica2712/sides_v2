<?php

namespace App\Services\Packing;

use App\Models\Seped\Pedido;
use App\Models\Sides\SidesFacturaGrupo;
use App\Models\Sides\SidesFacturaGrupoRen;
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
 * Anexar no funde pedidos: cada uno conserva su número y sus renglones, y el ERP emite una factura
 * por pedido. Los que salen juntos quedan en un mismo grupo de facturación (sides_factura_grupo).
 */
class RetencionService
{
    /** Estados en los que otro pedido del cliente todavía puede llegar a esta alcabala. */
    public const ESTADOS_EN_CAMINO = ['ALCABALA', 'RECIBIDO', 'PICKING', 'PACKING'];

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
     * retenidos; si salen dos o más a la vez quedan en un grupo de facturación.
     *
     * @param  array<int|string, string|null>  $bultos  bultos por pedido: al anexar suelen cambiar
     * @return list<int> pedidos liberados
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
            $pedidos = Pedido::query()->whereIn('id', $renglones->pluck('id_pedido')->all())->where('estado', 'PACKING')->lockForUpdate()->get();
            if ($pedidos->isEmpty()) {
                throw new PackingException('Esos pedidos ya no están retenidos.');
            }

            $ahora = Carbon::now();
            foreach ($pedidos as $pedido) {
                Pedido::query()->whereKey($pedido->id)->update(['estado' => 'PEND-FACTURA', 'feccompletado' => $ahora]);
                $this->despachoSeped->escribir($pedido->id);

                $cantidad = trim((string) ($bultos[$pedido->id] ?? ''));
                if ($cantidad !== '') {
                    DB::table('sides_pedido_operacion')->where('id_pedido', $pedido->id)->update(['cantBultos' => $cantidad]);
                }
            }

            $ids = $pedidos->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
            SidesRetencionRen::query()->where('id_retencion', $retencion->id)->whereIn('id_pedido', $ids)->whereNull('fec_liberado')->update(['fec_liberado' => $ahora]);

            if (count($ids) > 1) {
                $retencion->id_grupo = $this->agrupar($retencion, $ids, $usuario, $ahora);
                $retencion->save();
            }
            $this->cerrarSiQuedoVacia($retencion->id, $usuario->email);

            return $ids;
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

    private function agrupar(SidesRetencion $retencion, array $ids, SidesUsers $usuario, Carbon $ahora): int
    {
        $grupo = SidesFacturaGrupo::query()->create([
            'codisb' => $retencion->codisb,
            'codcli' => $retencion->codcli,
            'nomcli' => $retencion->nomcli,
            'estado' => 'ABIERTO',
            'usuario' => $usuario->email,
            'fecha' => $ahora,
        ]);

        foreach ($ids as $id) {
            SidesFacturaGrupoRen::query()->create(['id_grupo' => $grupo->id, 'id_pedido' => $id]);
        }

        return $grupo->id;
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
