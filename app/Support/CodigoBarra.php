<?php

namespace App\Support;

/**
 * Códigos de barras leídos con el lector. Muchos lectores mandan un UPC-A (12 dígitos) como EAN-13,
 * con un 0 adelante, o al revés; por eso, como en el picking y el packing (picking.js), los ceros a
 * la izquierda no cuentan al comparar.
 */
class CodigoBarra
{
    /** Hasta este largo se prueba con ceros adelante (EAN-14 / GTIN-14). */
    private const LARGO_MAXIMO = 14;

    public static function normalizar(?string $codigo): string
    {
        return ltrim(trim((string) $codigo), '0');
    }

    /**
     * Las formas en que el catálogo puede tener guardado el código: tal cual, sin ceros a la
     * izquierda y con ceros hasta 14 dígitos. Sirven para un whereIn que use el índice.
     *
     * @return list<string>
     */
    public static function variantes(string $codigo): array
    {
        $codigo = trim($codigo);
        $base = self::normalizar($codigo);
        if ($base === '' || ! ctype_digit($base)) {
            return [$codigo];
        }

        $variantes = [$codigo, $base];
        for ($largo = strlen($base) + 1; $largo <= self::LARGO_MAXIMO; $largo++) {
            $variantes[] = str_pad($base, $largo, '0', STR_PAD_LEFT);
        }

        return array_values(array_unique($variantes));
    }

    public static function iguales(?string $uno, ?string $otro): bool
    {
        $uno = self::normalizar($uno);

        return $uno !== '' && $uno === self::normalizar($otro);
    }
}
