<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Colores de la droguería. SEPED es el único dueño: el FT los configura allá y SEPED publica la
 * paleta ya calculada (con los arreglos de contraste) en tema_paleta, una fila por codisb, con las
 * mismas claves que sus variables --brand-*. SIDES solo la lee y la escribe sobre las variables de
 * resources/css/app.css; el cálculo no se copia aquí para no desincronizarse
 * (seped_v2 docs/plan_integracion_sides_v2.md, sección 6b).
 */
class TemaSides
{
    private const CACHE_SEGUNDOS = 300;

    /** `:root{--brand-…}` con la paleta de la sucursal, o '' si no tiene (quedan los colores de app.css). */
    public static function estilo(?string $codisb): string
    {
        $variables = collect(self::paleta($codisb))
            ->map(fn (string $color, string $clave) => '--brand-'.str_replace('_', '-', $clave).':'.$color.';')
            ->implode('');

        return $variables === '' ? '' : ":root{{$variables}}";
    }

    /** @return array<string, string> solo claves simples y colores válidos (lo que no, se descarta) */
    public static function paleta(?string $codisb): array
    {
        if ($codisb === null || $codisb === '') {
            return [];
        }

        return Cache::remember("sides_tema:{$codisb}", self::CACHE_SEGUNDOS, function () use ($codisb) {
            try {
                $json = DB::table('tema_paleta')->where('codisb', $codisb)->value('paleta');
            } catch (Throwable $e) {
                // Base sin la tabla todavía (SEPED no corrió su migración): colores por defecto.
                Log::warning("TemaSides: no se pudo leer tema_paleta de {$codisb}: {$e->getMessage()}");

                return [];
            }

            $paleta = is_string($json) ? json_decode($json, true) : null;

            return collect(is_array($paleta) ? $paleta : [])
                ->filter(fn ($color, $clave) => is_string($clave) && preg_match('/^[a-z_]+$/', $clave)
                    && is_string($color) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $color))
                ->all();
        });
    }
}
