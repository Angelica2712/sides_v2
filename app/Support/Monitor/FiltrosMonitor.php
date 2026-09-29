<?php

namespace App\Support\Monitor;

use App\Models\Sides\SidesMonitor;
use Illuminate\Support\Collection;

/**
 * Filtros con nombre del Monitor (sides_monitor, se administran en Filtro monitor).
 *
 * Un pedido entra a un filtro si su ruta contiene alguno de los fragmentos del criterio
 * (separados por coma, sin distinguir mayúsculas). La comparación se hace acá, en PHP, sobre la
 * lista de rutas con pedidos en proceso: así el conteo de cada pestaña, el listado filtrado y la
 * marca de cada pedido usan exactamente la misma regla, sin depender de la intercalación de MySQL.
 */
class FiltrosMonitor
{
    /** @param Collection<int, SidesMonitor> $filtros */
    public function __construct(private readonly Collection $filtros)
    {
    }

    public static function deSucursal(string $codisb): self
    {
        return new self(SidesMonitor::query()->where('codisb', $codisb)->orderBy('descrip')->get());
    }

    /** @return Collection<int, SidesMonitor> */
    public function todos(): Collection
    {
        return $this->filtros;
    }

    public function buscar(mixed $id): ?SidesMonitor
    {
        return is_numeric($id) ? $this->filtros->firstWhere('id', (int) $id) : null;
    }

    /** @return list<string> */
    public static function fragmentos(?string $criterio): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', (string) $criterio)),
            fn (string $fragmento) => $fragmento !== '',
        ));
    }

    public static function coincide(SidesMonitor $filtro, ?string $ruta): bool
    {
        if ($ruta === null || $ruta === '') {
            return false;
        }

        foreach (self::fragmentos($filtro->criterio) as $fragmento) {
            if (mb_stripos($ruta, $fragmento) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rutas (de las recibidas) que entran al filtro.
     *
     * @param  iterable<string|null>  $rutas
     * @return list<string>
     */
    public static function rutasDe(SidesMonitor $filtro, iterable $rutas): array
    {
        $coinciden = [];
        foreach ($rutas as $ruta) {
            if (self::coincide($filtro, $ruta)) {
                $coinciden[] = $ruta;
            }
        }

        return $coinciden;
    }

    /**
     * Marcas (caracterLogo) de los filtros en los que cae una ruta.
     *
     * @return list<array{texto: string, filtro: string}>
     */
    public function marcas(?string $ruta): array
    {
        return $this->filtros
            ->filter(fn (SidesMonitor $filtro) => self::tieneMarca($filtro) && self::coincide($filtro, $ruta))
            ->map(fn (SidesMonitor $filtro) => ['texto' => trim($filtro->caracterLogo), 'filtro' => $filtro->descrip])
            ->values()
            ->all();
    }

    /** El formulario legacy proponía "N/A" como marca: se trata como sin marca. */
    private static function tieneMarca(SidesMonitor $filtro): bool
    {
        $marca = trim((string) $filtro->caracterLogo);

        return $marca !== '' && strtoupper($marca) !== 'N/A';
    }
}
