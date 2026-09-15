<?php

namespace App\Support;

/**
 * Tamaños de etiqueta de bulto. Las claves son los valores de sides_cfg.formatoPersEtiq que usaban
 * los tres SIDES legacy (nombre de su plantilla PDF); sin valor se usaba rptetiqueta (13 × 8 cm).
 */
class FormatosEtiqueta
{
    /** clave => [nombre, ancho mm, alto mm] */
    public const FORMATOS = [
        'rptetiqueta' => ['13 × 8 cm', 130, 80],
        'rptetiqueta15x10' => ['15 × 10 cm', 150, 100],
        'rptetiqueta10x7' => ['9,5 × 7 cm', 95, 70],
        // El legacy declaraba 1000 × 620 mm por error; la plantilla era de 10 × 6,2 cm.
        'rptetiqueta10x6_2' => ['10 × 6,2 cm', 100, 62],
    ];

    /** El legacy comparaba sin distinguir mayúsculas; cualquier otro valor usa el formato por defecto. */
    public static function clave(?string $valor): string
    {
        foreach (array_keys(self::FORMATOS) as $clave) {
            if (strcasecmp($clave, trim((string) $valor)) === 0) {
                return $clave;
            }
        }

        return 'rptetiqueta';
    }

    /** @return array{clave: string, nombre: string, ancho: int, alto: int} */
    public static function de(?string $valor): array
    {
        $clave = self::clave($valor);
        [$nombre, $ancho, $alto] = self::FORMATOS[$clave];

        return ['clave' => $clave, 'nombre' => $nombre, 'ancho' => $ancho, 'alto' => $alto];
    }
}
