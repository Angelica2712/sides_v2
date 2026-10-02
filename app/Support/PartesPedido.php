<?php

namespace App\Support;

use App\Models\Seped\Pedido;
use Illuminate\Support\Collection;

/**
 * Pedidos que SEPED partió por exceso de renglones (cfg.numRengPedido) o por
 * los criterios de separación: el original conserva su número y cada parte
 * nueva lleva en pedido.idori el número del original. Siempre quedan con el
 * mismo cliente, así que se buscan por (codisb, codcli), que tiene índice.
 */
class PartesPedido
{
    /** Número del pedido original de la familia: el idori si es una parte, o el propio. */
    public static function raiz(object $pedido): int
    {
        $idori = trim((string) ($pedido->idori ?? ''));

        return ctype_digit($idori) && (int) $idori > 0 ? (int) $idori : (int) $pedido->id;
    }

    /**
     * Para una lista de pedidos (con id, codcli e idori), qué parte es cada uno.
     * Solo trae los que pertenecen a un pedido partido.
     *
     * @param  iterable<object>  $pedidos
     * @return array<int, array{raiz: int, parte: int, total: int}>
     */
    public static function deLista(string $codisb, iterable $pedidos): array
    {
        $pedidos = collect($pedidos);
        if ($pedidos->isEmpty()) {
            return [];
        }

        $raices = $pedidos->map(fn ($p) => self::raiz($p))->unique()->values();

        $familias = self::familias($codisb, $pedidos->pluck('codcli')->unique()->values(), $raices);

        $partes = [];
        foreach ($pedidos as $pedido) {
            $info = self::ubicar((int) $pedido->id, $familias->get(self::raiz($pedido), collect()), self::raiz($pedido));
            if ($info) {
                $partes[(int) $pedido->id] = $info;
            }
        }

        return $partes;
    }

    /**
     * Todos los pedidos de la familia de uno (el original primero), para el
     * detalle. Vacío si el pedido no fue partido.
     */
    public static function familia(Pedido $pedido): Collection
    {
        $raiz = self::raiz($pedido);
        $miembros = Pedido::query()
            ->where('codisb', $pedido->codisb)
            ->where('codcli', $pedido->codcli)
            ->where(fn ($q) => $q->where('id', $raiz)->orWhere('idori', (string) $raiz))
            ->orderBy('id')
            ->get(['id', 'idori', 'estado', 'numren', 'numund', 'documento']);

        return $miembros->count() > 1 || ($miembros->count() === 1 && $raiz !== (int) $pedido->id)
            ? $miembros
            : collect();
    }

    /** @return Collection<int, Collection<int, int>> ids de cada familia, por raíz */
    private static function familias(string $codisb, Collection $codclis, Collection $raices): Collection
    {
        $textos = $raices->map(fn ($r) => (string) $r)->all();

        return Pedido::query()
            ->where('codisb', $codisb)
            ->whereIn('codcli', $codclis->all())
            ->where(fn ($q) => $q->whereIn('id', $raices->all())->orWhereIn('idori', $textos))
            ->get(['id', 'idori'])
            ->groupBy(fn ($p) => self::raiz($p))
            ->map(fn ($grupo) => $grupo->pluck('id')->map(fn ($id) => (int) $id)->sort()->values());
    }

    /** @param  Collection<int, int>  $ids */
    private static function ubicar(int $id, Collection $ids, int $raiz): ?array
    {
        // Si el original ya no está (depurado), igual se cuenta como la parte 1.
        if (! $ids->contains($raiz)) {
            $ids = $ids->prepend($raiz);
        }

        if ($ids->count() < 2) {
            return null;
        }

        return ['raiz' => $raiz, 'parte' => $ids->search($id) + 1, 'total' => $ids->count()];
    }
}
