<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Fechas de `pedido` de SEPED. Las columnas fec* no aceptan null: "sin fecha" se guarda como
 * 2020-01-01 00:00:00 (2000-01-01 en fecentregado), así que cualquier fecha hasta esa se muestra vacía.
 */
class FechaSeped
{
    public const VACIA = '2020-01-01 00:00:00';

    public static function valor(mixed $fecha): ?Carbon
    {
        if (blank($fecha)) {
            return null;
        }

        $carbon = Carbon::parse($fecha);

        return $carbon->lessThanOrEqualTo(Carbon::parse(self::VACIA)) ? null : $carbon;
    }

    public static function mostrar(mixed $fecha, string $formato = 'd-m-Y H:i'): string
    {
        return self::valor($fecha)?->format($formato) ?? '—';
    }

    /** Valor para <input type="datetime-local">. */
    public static function paraInput(mixed $fecha): string
    {
        return self::valor($fecha)?->format('Y-m-d\TH:i') ?? '';
    }

    public static function paraGuardar(?string $fecha): string
    {
        return blank($fecha) ? self::VACIA : Carbon::parse($fecha)->format('Y-m-d H:i:s');
    }
}
