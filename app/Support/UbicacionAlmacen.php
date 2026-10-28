<?php

namespace App\Support;

/**
 * Ubicación de un producto. Por defecto, en el formato que SIDES recomienda a las droguerías:
 *
 *     ZONA-PASILLO-ANAQUEL-ESTANTE-POSICION     ej. PK-03-12-B-04
 *
 * - zona: 2 letras (PK picking, MY mayor, FR frío...);
 * - pasillo y anaquel: 2 dígitos; en cada pasillo los anaqueles impares van a la izquierda y los pares a la derecha;
 * - estante: 1 letra, A es el de abajo;
 * - posición: 2 dígitos, de izquierda a derecha mirando el anaquel.
 *
 * Cada droguería puede armar su propio formato en «Registrar ubicaciones» (ConfigAlmacen, clave
 * formato): qué partes usa, en qué orden, con qué nombre, cuántos dígitos o letras lleva cada una
 * y el separador. Por ejemplo PASILLO.RACK.NIVEL.HUECO = 03.12.2.04. Cada parte cumple uno de los
 * papeles de arriba (rol), así el Almacén 3D la sabe dibujar igual; sin zona, todo va a Picking.
 *
 * Se puede cortar por la derecha ("FR", "MY-02", "PK-03-12") y varias ubicaciones van separadas
 * con ";", la primera es la de picking. resources/js/ubicacion.js tiene la misma regla.
 */
class UbicacionAlmacen
{
    public const ROLES = ['zona' => 'Zona', 'pasillo' => 'Pasillo', 'anaquel' => 'Anaquel', 'estante' => 'Estante', 'posicion' => 'Posición'];

    /**
     * Tipo y largo que admite cada papel. La zona y los números de pasillo y anaquel forman las
     * claves del acomodo (PK-03-12), por eso no pasan del tamaño del formato de SIDES.
     */
    public const REGLAS = [
        'zona' => ['letras' => [2]],
        'pasillo' => ['numeros' => [1, 2], 'letras' => [1]],
        'anaquel' => ['numeros' => [1, 2], 'letras' => [1]],
        'estante' => ['letras' => [1], 'numeros' => [1, 2]],
        'posicion' => ['numeros' => [1, 2], 'letras' => [1]],
    ];

    public const SEPARADORES = ['-' => 'Guion ( - )', '.' => 'Punto ( . )', '/' => 'Barra ( / )', '_' => 'Guion bajo ( _ )', '' => 'Sin separador'];

    public const FORMATO_SIDES = [
        'separador' => '-',
        'partes' => [
            ['rol' => 'zona', 'nombre' => 'Zona', 'tipo' => 'letras', 'largo' => 2],
            ['rol' => 'pasillo', 'nombre' => 'Pasillo', 'tipo' => 'numeros', 'largo' => 2],
            ['rol' => 'anaquel', 'nombre' => 'Anaquel', 'tipo' => 'numeros', 'largo' => 2],
            ['rol' => 'estante', 'nombre' => 'Estante', 'tipo' => 'letras', 'largo' => 1],
            ['rol' => 'posicion', 'nombre' => 'Posición', 'tipo' => 'numeros', 'largo' => 2],
        ],
    ];

    /** Zona de las ubicaciones de un formato sin zona. */
    public const ZONA_SIN_ZONA = 'PK';

    /**
     * @return array{codigo: string, zona: string, pasillo: ?int, anaquel: ?int, estante: ?string, posicion: ?int}|null
     *         null si el texto no sigue el formato. El estante sale siempre como letra (A = el primero).
     */
    public static function interpretar(?string $texto, ?array $formato = null): ?array
    {
        $formato ??= self::FORMATO_SIDES;
        $codigo = strtoupper(str_replace(' ', '', trim((string) $texto)));
        if ($codigo === '' || ! preg_match(self::patron($formato), $codigo, $partes)) {
            return null;
        }

        $tipos = array_column($formato['partes'], 'tipo', 'rol');
        $valor = function (string $rol) use ($partes, $tipos): ?int {
            $texto = $partes[$rol] ?? '';
            if ($texto === '') {
                return null;
            }

            return $tipos[$rol] === 'letras' ? ord($texto) - ord('A') + 1 : (int) $texto;
        };

        $estante = $valor('estante');
        if ($estante !== null && ($estante < 1 || $estante > 26)) {
            return null;
        }

        return [
            'codigo' => $codigo,
            'zona' => ($partes['zona'] ?? '') !== '' ? $partes['zona'] : self::ZONA_SIN_ZONA,
            'pasillo' => $valor('pasillo'),
            'anaquel' => $valor('anaquel'),
            'estante' => $estante === null ? null : chr(ord('A') + $estante - 1),
            'posicion' => $valor('posicion'),
        ];
    }

    /**
     * Todas las ubicaciones de un campo con varias separadas por ";", en orden; las que no siguen
     * el formato se descartan.
     *
     * @return list<array{codigo: string, zona: string, pasillo: ?int, anaquel: ?int, estante: ?string, posicion: ?int}>
     */
    public static function todas(?string $texto, ?array $formato = null): array
    {
        return array_values(array_filter(array_map(
            fn (string $parte) => self::interpretar($parte, $formato),
            explode(';', (string) $texto),
        )));
    }

    /** Expresión regular del formato: las partes se pueden cortar por la derecha. */
    public static function patron(array $formato): string
    {
        $separador = preg_quote($formato['separador'], '/');
        $grupos = array_map(
            fn (array $parte) => sprintf('(?<%s>%s{%d})', $parte['rol'], $parte['tipo'] === 'letras' ? '[A-Z]' : '\d', $parte['largo']),
            $formato['partes'],
        );

        $resto = '';
        foreach (array_reverse(array_slice($grupos, 1)) as $grupo) {
            $resto = "(?:{$separador}{$grupo}{$resto})?";
        }

        return '/^'.$grupos[0].$resto.'$/';
    }

    /** Ejemplo del formato: zona PK, pasillo 3, anaquel 12, estante 2 (B), posición 4. */
    public static function ejemplo(array $formato): string
    {
        $valores = ['pasillo' => 3, 'anaquel' => 12, 'estante' => 2, 'posicion' => 4];

        return implode($formato['separador'], array_map(
            fn (array $parte) => match (true) {
                $parte['rol'] === 'zona' => 'PK',
                $parte['tipo'] === 'letras' => chr(ord('A') + $valores[$parte['rol']] - 1),
                default => str_pad((string) $valores[$parte['rol']], $parte['largo'], '0', STR_PAD_LEFT),
            },
            $formato['partes'],
        ));
    }

    /** Nombre del formato para los mensajes: ZONA-PASILLO-ANAQUEL-ESTANTE-POSICIÓN. */
    public static function nombre(array $formato): string
    {
        return implode($formato['separador'] === '' ? ' ' : $formato['separador'], array_map(
            fn (array $parte) => mb_strtoupper($parte['nombre']),
            $formato['partes'],
        ));
    }

    /** El formato guardado, o el de SIDES si falta o no es válido. */
    public static function formato(mixed $formato): array
    {
        $limpio = is_array($formato) ? self::limpiar($formato) : null;

        return $limpio && self::errorDeFormato($limpio) === null ? $limpio : self::FORMATO_SIDES;
    }

    /** Ordena los datos de un formato que llega del formulario, sin validarlo todavía. */
    public static function limpiar(array $formato): array
    {
        return [
            'separador' => (string) ($formato['separador'] ?? ''),
            'partes' => array_values(array_map(fn (array $parte) => [
                'rol' => (string) ($parte['rol'] ?? ''),
                'nombre' => mb_substr(trim((string) ($parte['nombre'] ?? '')), 0, 20) ?: (self::ROLES[$parte['rol'] ?? ''] ?? ''),
                'tipo' => (string) ($parte['tipo'] ?? ''),
                'largo' => (int) ($parte['largo'] ?? 0),
            ], array_filter((array) ($formato['partes'] ?? []), 'is_array'))),
        ];
    }

    /** Qué tiene mal un formato ya limpio, o null si sirve. */
    public static function errorDeFormato(array $formato): ?string
    {
        if (! array_key_exists($formato['separador'], self::SEPARADORES)) {
            return 'Elige el separador de las partes.';
        }
        $partes = $formato['partes'];
        if (count($partes) < 2 || count($partes) > count(self::ROLES)) {
            return 'El formato lleva entre 2 y 5 partes.';
        }
        foreach ($partes as $parte) {
            $reglas = self::REGLAS[$parte['rol']] ?? null;
            if (! $reglas) {
                return 'Elige qué es cada parte: zona, pasillo, anaquel, estante o posición.';
            }
            if (! in_array($parte['largo'], $reglas[$parte['tipo']] ?? [], true)) {
                return "«{$parte['nombre']}» no puede ser así: ".self::reglaLegible($parte['rol']).'.';
            }
        }
        $roles = array_column($partes, 'rol');
        foreach (array_count_values($roles) as $rol => $veces) {
            if ($veces > 1) {
                return 'Hay dos partes que son '.mb_strtolower(self::ROLES[$rol]).': cada parte cumple un papel distinto.';
            }
        }
        if (! in_array('pasillo', $roles, true) || ! in_array('anaquel', $roles, true)) {
            return 'El formato necesita el pasillo y el anaquel para ubicar el producto en el almacén.';
        }

        return null;
    }

    /** "como posición van 1 o 2 números, o 1 letra" */
    private static function reglaLegible(string $rol): string
    {
        $opciones = [];
        foreach (self::REGLAS[$rol] as $tipo => $largos) {
            $unidad = $tipo === 'letras' ? ['letra', 'letras'] : ['número', 'números'];
            $opciones[] = implode(' o ', $largos).' '.$unidad[max($largos) > 1 ? 1 : 0];
        }

        return 'como '.mb_strtolower(self::ROLES[$rol]).' van '.implode(', o ', $opciones);
    }
}
