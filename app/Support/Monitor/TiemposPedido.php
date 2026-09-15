<?php

namespace App\Support\Monitor;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Minutos de espera, picking y packing de un pedido, con la misma regla del monitor legacy
 * (sides_droactiva admin/monitor/index.blade.php + helper MinutosTranscurridos):
 *
 * - RECIBIDO: espera = procesado → ahora.
 * - PICKING:  espera = procesado → inicio picking; picking = inicio picking → ahora.
 * - PACKING:  espera = procesado → inicio picking; picking = inicio picking → inicio packing;
 *             packing = inicio packing → ahora.
 *
 * Minutos enteros, redondeados hacia abajo y en valor absoluto, como el legacy.
 */
final class TiemposPedido
{
    /**
     * Umbrales del semáforo visual del monitor v2 (el legacy no coloreaba la demora).
     * Candidatos a pasar a sides_cfg si cada droguería necesita los suyos.
     */
    public const MINUTOS_ATENCION = 30;

    public const MINUTOS_DEMORADO = 60;

    /** @return array{espera: int, picking: int, packing: int} */
    public static function calcular(object $pedido, ?CarbonInterface $ahora = null): array
    {
        $ahora ??= Carbon::now();
        $tiempos = ['espera' => 0, 'picking' => 0, 'packing' => 0];

        switch ($pedido->estado) {
            case 'RECIBIDO':
                $tiempos['espera'] = self::minutos($pedido->fecprocesado, $ahora);
                break;
            case 'PICKING':
                $tiempos['espera'] = self::minutos($pedido->fecprocesado, $pedido->fecpicking);
                $tiempos['picking'] = self::minutos($pedido->fecpicking, $ahora);
                break;
            case 'PACKING':
                $tiempos['espera'] = self::minutos($pedido->fecprocesado, $pedido->fecpicking);
                $tiempos['picking'] = self::minutos($pedido->fecpicking, $pedido->fecpacking);
                $tiempos['packing'] = self::minutos($pedido->fecpacking, $ahora);
                break;
        }

        return $tiempos;
    }

    /** Minutos que lleva el pedido en su estado actual. */
    public static function enEstadoActual(string $estado, array $tiempos): int
    {
        return match ($estado) {
            'PICKING' => $tiempos['picking'],
            'PACKING' => $tiempos['packing'],
            default => $tiempos['espera'],
        };
    }

    /** @return 'normal'|'atencion'|'demorado' */
    public static function nivel(int $minutos): string
    {
        return match (true) {
            $minutos >= self::MINUTOS_DEMORADO => 'demorado',
            $minutos >= self::MINUTOS_ATENCION => 'atencion',
            default => 'normal',
        };
    }

    /** 45 → "45 min", 90 → "1 h 30 min", 1500 → "1 d 1 h". */
    public static function formatear(int $minutos): string
    {
        if ($minutos < 60) {
            return "{$minutos} min";
        }

        if ($minutos < 1440) {
            $horas = intdiv($minutos, 60);
            $resto = $minutos % 60;

            return $resto ? "{$horas} h {$resto} min" : "{$horas} h";
        }

        $dias = intdiv($minutos, 1440);
        $horas = intdiv($minutos % 1440, 60);

        return $horas ? "{$dias} d {$horas} h" : "{$dias} d";
    }

    private static function minutos(mixed $desde, mixed $hasta): int
    {
        if (empty($desde) || empty($hasta)) {
            return 0;
        }

        return intdiv(abs(Carbon::parse($hasta)->getTimestamp() - Carbon::parse($desde)->getTimestamp()), 60);
    }
}
