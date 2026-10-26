<?php

namespace App\Support;

use App\Models\Sides\SidesAlmacenCfg;

/**
 * Cómo está acomodado el almacén de una droguería. El código de ubicación es siempre
 * ZONA-PASILLO-ANAQUEL-ESTANTE-POSICION (UbicacionAlmacen); esto dice dónde queda físicamente
 * cada número. Sin configuración se usa el acomodo recomendado (POR_DEFECTO).
 * resources/js/ubicacion.js aplica las mismas reglas en el visor.
 */
class ConfigAlmacen
{
    public const NUMERACIONES = [
        'alternada' => ['Alternada', 'Impares a la izquierda y pares a la derecha, avanzando desde la entrada (1 y 2 quedan frente a frente).'],
        'u' => ['En U', 'Se numera todo el lado izquierdo desde la entrada y se regresa por el derecho hacia la entrada.'],
        'izquierda' => ['Un solo lado', 'Los anaqueles están solo a un lado del pasillo (por ejemplo, contra la pared).'],
    ];

    public const ESTANTE_A = [
        'abajo' => ['Abajo', 'El estante A es el del piso y las letras suben.'],
        'arriba' => ['Arriba', 'El estante A es el de arriba y las letras bajan.'],
    ];

    public const POSICION_1 = [
        'izquierda' => ['A la izquierda', 'Mirando el anaquel desde el pasillo, la posición 1 es la de la izquierda.'],
        'derecha' => ['A la derecha', 'Mirando el anaquel desde el pasillo, la posición 1 es la de la derecha.'],
    ];

    public const ZONAS = ['PK' => 'Picking', 'MY' => 'Mayor', 'FR' => 'Frío', 'PS' => 'Psicotrópicos', 'DV' => 'Devoluciones'];

    public const POR_DEFECTO = [
        'numeracion' => 'alternada',
        'estanteA' => 'abajo',
        'posicion1' => 'izquierda',
        'estantes' => 4,
        'posiciones' => 4,
        'zonas' => [],
        'disposicion' => [],
    ];

    /** Anaqueles que se pueden mover a mano en el plano, como máximo. */
    public const MAXIMO_MOVIDOS = 5000;

    /**
     * disposicion: anaqueles movidos a mano en el plano de Administración, por "ZONA-PASILLO-ANAQUEL"
     * (PK-03-12) => centro en metros del plano (x, z) y giro (0, 90, 180 o 270; 0 = el frente mira
     * hacia +z). Los que no están ahí se acomodan solos según la numeración.
     *
     * @return array{numeracion: string, estanteA: string, posicion1: string, estantes: int, posiciones: int, zonas: array<string, string>, disposicion: array<string, array{x: float, z: float, giro: int}>}
     */
    public static function de(string $codisb): array
    {
        return self::normalizar(SidesAlmacenCfg::query()->find($codisb)?->config ?? []);
    }

    /** Completa con los valores por defecto y descarta lo que no sea válido. */
    public static function normalizar(array $config): array
    {
        $opcion = fn (string $clave, array $opciones) => isset($opciones[$config[$clave] ?? null]) ? $config[$clave] : self::POR_DEFECTO[$clave];
        $entero = fn (string $clave, int $maximo) => min(max((int) ($config[$clave] ?? self::POR_DEFECTO[$clave]), 1), $maximo);

        $zonas = [];
        foreach ((array) ($config['zonas'] ?? []) as $codigo => $nombre) {
            $codigo = strtoupper(trim((string) $codigo));
            $nombre = trim((string) $nombre);
            // Solo se guarda el nombre si cambia el que ya trae SIDES.
            if (preg_match('/^[A-Z]{2}$/', $codigo) && $nombre !== '' && $nombre !== (self::ZONAS[$codigo] ?? null)) {
                $zonas[$codigo] = mb_substr($nombre, 0, 40);
            }
        }
        ksort($zonas);

        $disposicion = [];
        foreach ((array) ($config['disposicion'] ?? []) as $clave => $lugar) {
            if (count($disposicion) >= self::MAXIMO_MOVIDOS) {
                break;
            }
            if (! preg_match('/^[A-Z]{2}-\d{2}-\d{2}$/', (string) $clave) || ! is_array($lugar)
                || ! is_numeric($lugar['x'] ?? null) || ! is_numeric($lugar['z'] ?? null)) {
                continue;
            }
            $giro = (((int) round(((float) ($lugar['giro'] ?? 0)) / 90)) * 90 % 360 + 360) % 360;
            $metros = fn ($valor) => round(min(max((float) $valor, -1000), 1000), 2);
            $disposicion[$clave] = ['x' => $metros($lugar['x']), 'z' => $metros($lugar['z']), 'giro' => $giro];
        }
        ksort($disposicion);

        return [
            'numeracion' => $opcion('numeracion', self::NUMERACIONES),
            'estanteA' => $opcion('estanteA', self::ESTANTE_A),
            'posicion1' => $opcion('posicion1', self::POSICION_1),
            'estantes' => $entero('estantes', 12),
            'posiciones' => $entero('posiciones', 30),
            'zonas' => $zonas,
            'disposicion' => $disposicion,
        ];
    }
}
