<?php

namespace App\Support;

use App\Models\Sides\SidesAlmacenCfg;

/**
 * Cómo está acomodado el almacén de una droguería y en qué formato escribe sus ubicaciones (por
 * defecto ZONA-PASILLO-ANAQUEL-ESTANTE-POSICION, ver UbicacionAlmacen); esto dice dónde queda
 * físicamente cada número. Sin configuración se usa el acomodo recomendado (POR_DEFECTO).
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
        'pasillos' => [],
        'quitados' => [],
        'extras' => [],
        'disposicion' => [],
        'entradas' => [],
        'formato' => UbicacionAlmacen::FORMATO_SIDES,
    ];

    /** La numeración que recomienda SIDES; la deja puesta el asistente «Armar almacén». */
    public const NUMERACION_SIDES = ['numeracion' => 'alternada', 'estanteA' => 'abajo', 'posicion1' => 'izquierda'];

    /** Anaqueles que se pueden mover a mano en el plano, como máximo. */
    public const MAXIMO_MOVIDOS = 5000;

    /** Pasillos que se pueden registrar, como máximo, y anaqueles a cada lado (el número de anaquel tiene 2 dígitos). */
    public const MAXIMO_PASILLOS = 500;

    public const MAXIMO_POR_LADO = 49;

    /** Puertas que se pueden marcar en el plano, como máximo. */
    public const MAXIMO_ENTRADAS = 20;

    /**
     * pasillos: pasillos registrados a mano por "ZONA-PASILLO" (PK-03) => cuántos anaqueles hay a
     * la izquierda y a la derecha de quien entra (con la numeración de SIDES: impares a la
     * izquierda, pares a la derecha) y, si se dijo, cuántos estantes y huecos tienen; si no, los
     * del almacén. El plano los dibuja completos aunque todavía no haya productos ubicados en ellos.
     * Se acepta también el formato anterior, un número de anaqueles (1 a N).
     *
     * quitados: anaqueles de los pasillos registrados que se borraron en el plano ("PK-03-05"),
     * porque ahí no hay anaquel. Uno con productos ubicados se sigue dibujando igual.
     *
     * extras: anaqueles sueltos con el número que se les puso a mano en el 3D ("PK-03-15"), por
     * ejemplo al cambiarle el número a uno de un pasillo registrado (el viejo pasa a quitados).
     *
     * disposicion: anaqueles movidos a mano en el plano, por "ZONA-PASILLO-ANAQUEL"
     * (PK-03-12) => centro en metros del plano (x, z) y giro (0, 90, 180 o 270; 0 = el frente mira
     * hacia +z). Los que no están ahí se acomodan solos según la numeración.
     *
     * entradas: puertas del almacén puestas en el plano, con su centro (x, z), su giro (0 = se
     * entra caminando hacia +z) y un nombre opcional ("Carga", "Principal").
     *
     * formato: cómo escribe la droguería sus ubicaciones (UbicacionAlmacen::FORMATO_SIDES si no
     * armó uno propio en «Registrar ubicaciones»).
     *
     * @return array{numeracion: string, estanteA: string, posicion1: string, estantes: int, posiciones: int, zonas: array<string, string>, pasillos: array<string, array{izquierda: int, derecha: int, estantes: ?int, posiciones: ?int}>, quitados: list<string>, extras: list<string>, disposicion: array<string, array{x: float, z: float, giro: int}>, entradas: list<array{x: float, z: float, giro: int, nombre: string}>, formato: array{separador: string, partes: list<array{rol: string, nombre: string, tipo: string, largo: int}>}}
     */
    public static function de(string $codisb): array
    {
        return self::normalizar(SidesAlmacenCfg::query()->find($codisb)?->config ?? []);
    }

    /** Formato de las ubicaciones de la droguería. */
    public static function formatoDe(?string $codisb): array
    {
        return $codisb ? self::de($codisb)['formato'] : UbicacionAlmacen::FORMATO_SIDES;
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

        $pasillos = [];
        foreach ((array) ($config['pasillos'] ?? []) as $clave => $valor) {
            if (count($pasillos) >= self::MAXIMO_PASILLOS) {
                break;
            }
            if (! preg_match('/^[A-Z]{2}-(\d{2})$/', (string) $clave, $partes) || (int) $partes[1] < 1) {
                continue;
            }
            $pasillo = self::pasillo($valor);
            if ($pasillo) {
                $pasillos[$clave] = $pasillo;
            }
        }
        ksort($pasillos);

        // Solo de pasillos registrados: si el pasillo se quitó de la lista, sus borrados sobran.
        $quitados = array_values(array_unique(array_filter(
            array_map(fn ($clave) => (string) $clave, (array) ($config['quitados'] ?? [])),
            fn (string $clave) => preg_match('/^[A-Z]{2}-\d{2}-\d{2}$/', $clave) === 1 && isset($pasillos[substr($clave, 0, 5)]),
        )));
        sort($quitados);
        $quitados = array_slice($quitados, 0, self::MAXIMO_MOVIDOS);

        // Anaquel 00 o pasillo 00 no existen en el formato.
        $extras = array_values(array_unique(array_filter(
            array_map(fn ($clave) => (string) $clave, (array) ($config['extras'] ?? [])),
            fn (string $clave) => preg_match('/^[A-Z]{2}-(\d{2})-(\d{2})$/', $clave, $partes) === 1 && (int) $partes[1] > 0 && (int) $partes[2] > 0,
        )));
        sort($extras);
        $extras = array_slice($extras, 0, self::MAXIMO_MOVIDOS);

        $esLugar = fn ($lugar) => is_array($lugar) && is_numeric($lugar['x'] ?? null) && is_numeric($lugar['z'] ?? null);
        $giro = fn ($lugar) => (((int) round(((float) ($lugar['giro'] ?? 0)) / 90)) * 90 % 360 + 360) % 360;
        $metros = fn ($valor) => round(min(max((float) $valor, -1000), 1000), 2);

        $disposicion = [];
        foreach ((array) ($config['disposicion'] ?? []) as $clave => $lugar) {
            if (count($disposicion) >= self::MAXIMO_MOVIDOS) {
                break;
            }
            if (! preg_match('/^[A-Z]{2}-\d{2}-\d{2}$/', (string) $clave) || ! $esLugar($lugar)) {
                continue;
            }
            $disposicion[$clave] = ['x' => $metros($lugar['x']), 'z' => $metros($lugar['z']), 'giro' => $giro($lugar)];
        }
        ksort($disposicion);

        $entradas = [];
        foreach (array_values((array) ($config['entradas'] ?? [])) as $lugar) {
            if (count($entradas) >= self::MAXIMO_ENTRADAS) {
                break;
            }
            if ($esLugar($lugar)) {
                $entradas[] = [
                    'x' => $metros($lugar['x']),
                    'z' => $metros($lugar['z']),
                    'giro' => $giro($lugar),
                    'nombre' => mb_substr(trim((string) ($lugar['nombre'] ?? '')), 0, 30),
                ];
            }
        }

        return [
            'numeracion' => $opcion('numeracion', self::NUMERACIONES),
            'estanteA' => $opcion('estanteA', self::ESTANTE_A),
            'posicion1' => $opcion('posicion1', self::POSICION_1),
            'estantes' => $entero('estantes', 12),
            'posiciones' => $entero('posiciones', 30),
            'zonas' => $zonas,
            'pasillos' => $pasillos,
            'quitados' => $quitados,
            'extras' => $extras,
            'disposicion' => $disposicion,
            'entradas' => $entradas,
            'formato' => UbicacionAlmacen::formato($config['formato'] ?? null),
        ];
    }

    /**
     * Un pasillo registrado, o null si no es válido.
     *
     * @return array{izquierda: int, derecha: int, estantes: ?int, posiciones: ?int}|null
     */
    public static function pasillo(mixed $valor): ?array
    {
        if (is_array($valor)) {
            $izquierda = (int) ($valor['izquierda'] ?? 0);
            $derecha = (int) ($valor['derecha'] ?? 0);
        } else {
            // Formato anterior: N anaqueles numerados de 1 a N.
            $izquierda = intdiv((int) $valor + 1, 2);
            $derecha = intdiv((int) $valor, 2);
        }
        if ($izquierda < 0 || $derecha < 0 || $izquierda + $derecha < 1
            || $izquierda > self::MAXIMO_POR_LADO || $derecha > self::MAXIMO_POR_LADO) {
            return null;
        }

        $medida = fn (string $clave, int $maximo) => is_array($valor) && is_numeric($valor[$clave] ?? null)
            ? min(max((int) $valor[$clave], 1), $maximo)
            : null;

        return [
            'izquierda' => $izquierda,
            'derecha' => $derecha,
            'estantes' => $medida('estantes', 12),
            'posiciones' => $medida('posiciones', 30),
        ];
    }

    /**
     * Números de los anaqueles de un pasillo registrado: impares a la izquierda, pares a la derecha.
     *
     * @param  array{izquierda: int, derecha: int}  $pasillo
     * @return list<int>
     */
    public static function anaquelesDe(array $pasillo): array
    {
        return array_merge(
            $pasillo['izquierda'] ? range(1, 2 * $pasillo['izquierda'] - 1, 2) : [],
            $pasillo['derecha'] ? range(2, 2 * $pasillo['derecha'], 2) : [],
        );
    }
}
