<?php

namespace App\Support;

/**
 * Ubicación de un producto en el formato que SIDES recomienda a las droguerías:
 *
 *     ZONA-PASILLO-ANAQUEL-ESTANTE-POSICION     ej. PK-03-12-B-04
 *
 * - zona: 2 letras (PK picking, MY mayor, FR frío...);
 * - pasillo y anaquel: 2 dígitos; en cada pasillo los anaqueles impares van a la izquierda y los pares a la derecha;
 * - estante: 1 letra, A es el de abajo;
 * - posición: 2 dígitos, de izquierda a derecha mirando el anaquel.
 *
 * Se puede cortar por la derecha ("FR", "MY-02", "PK-03-12") y varias ubicaciones van separadas
 * con ";", la primera es la de picking. resources/js/ubicacion.js tiene la misma regla.
 */
class UbicacionAlmacen
{
    private const PATRON = '/^([A-Z]{2})(?:-(\d{2})(?:-(\d{2})(?:-([A-Z])(?:-(\d{2}))?)?)?)?$/';

    /**
     * @return array{codigo: string, zona: string, pasillo: ?int, anaquel: ?int, estante: ?string, posicion: ?int}|null
     *         null si el texto no sigue el formato
     */
    public static function interpretar(?string $texto): ?array
    {
        $codigo = strtoupper(str_replace(' ', '', trim((string) $texto)));
        if (! preg_match(self::PATRON, $codigo, $partes)) {
            return null;
        }

        $numero = fn (int $i) => isset($partes[$i]) && $partes[$i] !== '' ? (int) $partes[$i] : null;

        return [
            'codigo' => $codigo,
            'zona' => $partes[1],
            'pasillo' => $numero(2),
            'anaquel' => $numero(3),
            'estante' => isset($partes[4]) && $partes[4] !== '' ? $partes[4] : null,
            'posicion' => $numero(5),
        ];
    }

    /**
     * Todas las ubicaciones de un campo con varias separadas por ";", en orden; las que no siguen
     * el formato se descartan.
     *
     * @return list<array{codigo: string, zona: string, pasillo: ?int, anaquel: ?int, estante: ?string, posicion: ?int}>
     */
    public static function todas(?string $texto): array
    {
        return array_values(array_filter(array_map(
            fn (string $parte) => self::interpretar($parte),
            explode(';', (string) $texto),
        )));
    }
}
