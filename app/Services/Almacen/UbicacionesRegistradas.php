<?php

namespace App\Services\Almacen;

use App\Models\Sides\SidesProductoUbicacion;
use App\Models\Sides\SidesUsers;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ubicaciones que la droguería registra desde SIDES (sides_producto_ubicacion). Ganan sobre la
 * del ERP (producto.ubicacion y la copia que trae pedren), que no se toca: así el catálogo puede
 * seguir sincronizándose sin pisar lo que se numeró en el almacén.
 */
class UbicacionesRegistradas
{
    public const TABLA = 'sides_producto_ubicacion';

    /**
     * Agrega la ubicación registrada (alias su) a una consulta sobre una tabla con codisb y
     * codprod (producto o pedren).
     */
    public static function unir(Builder $consulta, string $tabla): Builder
    {
        return $consulta->leftJoin(self::TABLA.' as su', function (JoinClause $join) use ($tabla) {
            $join->on('su.codisb', '=', "{$tabla}.codisb")->on('su.codprod', '=', "{$tabla}.codprod");
        });
    }

    /**
     * Expresión SQL de la ubicación que vale: la registrada en SIDES y, si no hay, las que se
     * pasen en orden (por ejemplo "op.ubicacion", "pedren.ubicacion").
     */
    public static function sql(string ...$respaldos): string
    {
        $partes = array_map(fn (string $columna) => "NULLIF({$columna}, '')", ['su.ubicacion', ...$respaldos]);

        return 'COALESCE('.implode(', ', $partes).", '')";
    }

    /**
     * @param  iterable<string>  $codprods
     * @return Collection<string, string> codprod => ubicación registrada
     */
    public function de(string $codisb, iterable $codprods): Collection
    {
        $codigos = collect($codprods)->map(fn ($codigo) => (string) $codigo)->unique()->values();
        if ($codigos->isEmpty()) {
            return collect();
        }

        return SidesProductoUbicacion::query()
            ->where('codisb', $codisb)
            ->whereIn('codprod', $codigos->all())
            ->pluck('ubicacion', 'codprod');
    }

    public function guardar(string $codisb, string $codprod, string $ubicacion, SidesUsers $usuario): SidesProductoUbicacion
    {
        return SidesProductoUbicacion::query()->updateOrCreate(
            ['codisb' => $codisb, 'codprod' => $codprod],
            ['ubicacion' => $ubicacion, 'usuario_id' => $usuario->id, 'usuario' => $usuario->name],
        );
    }

    /** Quita la ubicación registrada: el producto vuelve a la del ERP. */
    public function quitar(string $codisb, string $codprod): bool
    {
        return SidesProductoUbicacion::query()->where('codisb', $codisb)->where('codprod', $codprod)->delete() > 0;
    }

    /** Últimas ubicaciones registradas en la droguería, la más reciente primero. */
    public function recientes(string $codisb, int $cantidad = 10): Collection
    {
        return DB::table(self::TABLA.' as su')
            ->leftJoin('producto', function (JoinClause $join) {
                $join->on('producto.codisb', '=', 'su.codisb')->on('producto.codprod', '=', 'su.codprod');
            })
            ->where('su.codisb', $codisb)
            ->orderByDesc('su.updated_at')
            ->orderByDesc('su.id')
            ->limit($cantidad)
            ->get(['su.codprod', 'su.ubicacion', 'su.usuario', 'su.updated_at', 'producto.desprod']);
    }
}
