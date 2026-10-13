<?php

namespace App\Services\Despacho;

use App\Models\Seped\Pedido;
use App\Models\Sides\SidesCfg;
use App\Models\Sides\SidesUsers;
use App\Support\PartesPedido;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * sides_cfg.unirPedidosPartidos: las partes en que SEPED separó un pedido (pedido.idori) se
 * trabajan en SIDES como un solo pedido: se toman, se abren, se terminan y se liberan juntas.
 * En SEPED siguen siendo pedidos aparte (cada uno con su estado, su despacho y su factura);
 * aquí solo se decide qué pedidos acompañan a uno en cada etapa.
 *
 * Con la opción apagada, cada método devuelve solo el pedido pedido.
 */
class PedidosUnidos
{
    public function activo(string $codisb): bool
    {
        // Lectura fresca: decide qué pedidos se mueven juntos.
        return (bool) SidesCfg::query()->whereKey($codisb)->value('unirPedidosPartidos');
    }

    /**
     * Partes que el usuario puede tomar junto con el pedido: las que siguen para picking,
     * no están en un lote de Batch Picking y no las tiene otro operario.
     *
     * @return list<int>
     */
    public function paraTomar(SidesUsers $usuario, int $pedidoId): array
    {
        return $this->con($pedidoId, $this->partes($usuario, $pedidoId, ['RECIBIDO', 'PICKING'])
            ->filter(fn ($parte) => $parte->lote === null)
            ->reject(fn ($parte) => $parte->despasignado && $parte->despachador && $parte->despachador !== $usuario->name));
    }

    /**
     * Partes que el usuario tiene en picking junto con el pedido.
     *
     * @return list<int>
     */
    public function enPicking(SidesUsers $usuario, int $pedidoId): array
    {
        return $this->con($pedidoId, $this->partes($usuario, $pedidoId, ['PICKING'])
            ->filter(fn ($parte) => $parte->lote === null && $parte->despasignado && $parte->despachador === $usuario->name));
    }

    /**
     * Partes en packing que el usuario empaca junto con el pedido: las suyas y las que nadie abrió.
     *
     * @return list<int>
     */
    public function enPacking(SidesUsers $usuario, int $pedidoId): array
    {
        return $this->con($pedidoId, $this->partes($usuario, $pedidoId, ['PACKING'])
            ->reject(fn ($parte) => $parte->embalador && $parte->embalador !== $usuario->name));
    }

    /**
     * Junta en una sola fila las partes de cada pedido de una lista (Picking, Packing). La fila
     * lleva el número de la primera parte, los totales sumados y en `unidos` todos los números.
     *
     * @param  Collection<int, object>  $pedidos
     * @param  callable(Collection<int, object>): array<string, mixed>  $resumen  campos propios de la pantalla
     * @return Collection<int, object>
     */
    public function agrupar(Collection $pedidos, callable $resumen): Collection
    {
        return $pedidos
            ->groupBy(fn ($pedido) => PartesPedido::raiz($pedido))
            ->map(function (Collection $partes) use ($resumen) {
                $partes = $partes->sortBy('id')->values();
                $fila = clone $partes->first();
                $fila->unidos = $partes->pluck('id')->map(fn ($id) => (int) $id)->all();
                $fila->numren = $partes->sum('numren');
                $fila->numund = $partes->sum('numund');
                $fila->recipiente = $partes->pluck('recipiente')->filter()->first();
                foreach ($resumen($partes) as $campo => $valor) {
                    $fila->{$campo} = $valor;
                }

                return $fila;
            })
            ->values();
    }

    /** @return Collection<int, object> partes de la familia en esos estados, con su operación y su lote */
    private function partes(SidesUsers $usuario, int $pedidoId, array $estados): Collection
    {
        if (! $this->activo($usuario->codisb)) {
            return collect();
        }

        $pedido = Pedido::query()->where('codisb', $usuario->codisb)->whereKey($pedidoId)->first(['id', 'idori', 'codcli']);
        if (! $pedido) {
            return collect();
        }

        $raiz = PartesPedido::raiz($pedido);

        return DB::table('pedido')
            ->leftJoin('sides_pedido_operacion as op', 'op.id_pedido', '=', 'pedido.id')
            ->leftJoin('sides_alcabala_lote_pedido as alp', 'alp.numped', '=', 'pedido.id')
            ->where('pedido.codisb', $usuario->codisb)
            ->where('pedido.codcli', $pedido->codcli)
            ->where(fn ($q) => $q->where('pedido.id', $raiz)->orWhere('pedido.idori', (string) $raiz))
            ->whereIn('pedido.estado', $estados)
            ->orderBy('pedido.id')
            ->get(['pedido.id', 'op.despachador', 'op.despasignado', 'op.embalador', 'alp.id_lote as lote']);
    }

    /**
     * El pedido pedido va siempre en la lista, aunque no cumpla: así el servicio que lo
     * trabaja es quien explica por qué no se puede.
     *
     * @return list<int>
     */
    private function con(int $pedidoId, Collection $partes): array
    {
        return $partes->pluck('id')->map(fn ($id) => (int) $id)->push($pedidoId)->unique()->sort()->values()->all();
    }
}
