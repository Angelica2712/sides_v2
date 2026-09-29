<?php

namespace App\Support;

/** Segundos a texto corto para informes: "1h 05m 03s", "4m 10s", "12s". */
class Duracion
{
    public static function texto(int|float|string|null $segundos): string
    {
        if ($segundos === null || $segundos === '') {
            return '—';
        }

        $total = (int) round((float) $segundos);
        $horas = intdiv($total, 3600);
        $minutos = intdiv($total % 3600, 60);
        $resto = $total % 60;

        if ($horas > 0) {
            return sprintf('%dh %02dm %02ds', $horas, $minutos, $resto);
        }

        return $minutos > 0 ? sprintf('%dm %02ds', $minutos, $resto) : "{$resto}s";
    }
}
