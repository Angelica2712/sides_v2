<?php

namespace App\Support;

use App\Models\Sides\SidesCfg;

/**
 * Tamaños de etiqueta de bulto y de ticket. Las claves de FORMATOS son los valores de
 * sides_cfg.formatoPersEtiq que usaban los tres SIDES legacy (nombre de su plantilla PDF); sin
 * valor se usaba rptetiqueta (13 × 8 cm). Con PERSONALIZADO la medida sale de
 * sides_cfg.etiquetaAncho / etiquetaAlto, en milímetros.
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

    public const PERSONALIZADO = 'personalizado';

    /** Límites de una etiqueta a medida, en mm (ancho y alto). */
    public const MIN_MM = 25;
    public const MAX_MM = 300;

    /** Ancho del papel del ticket, en mm: 77 era el del legacy (rptticket). */
    public const TICKET_ANCHO = 77;
    public const TICKET_MIN = 40;
    public const TICKET_MAX = 120;

    /** El legacy comparaba sin distinguir mayúsculas; cualquier otro valor usa el formato por defecto. */
    public static function clave(?string $valor): string
    {
        foreach ([...array_keys(self::FORMATOS), self::PERSONALIZADO] as $clave) {
            if (strcasecmp($clave, trim((string) $valor)) === 0) {
                return $clave;
            }
        }

        return 'rptetiqueta';
    }

    /**
     * Formato de la etiqueta de una droguería. Un personalizado sin medidas válidas cae al de por defecto.
     *
     * @return array{clave: string, nombre: string, ancho: int, alto: int, escala: float}
     */
    public static function de(?SidesCfg $cfg): array
    {
        $clave = self::clave($cfg?->formatoPersEtiq);
        $ancho = (int) $cfg?->etiquetaAncho;
        $alto = (int) $cfg?->etiquetaAlto;

        if ($clave === self::PERSONALIZADO && self::medidaValida($ancho) && self::medidaValida($alto)) {
            $nombre = self::cm($ancho).' × '.self::cm($alto).' cm';
        } else {
            $clave = $clave === self::PERSONALIZADO ? 'rptetiqueta' : $clave;
            [$nombre, $ancho, $alto] = self::FORMATOS[$clave];
        }

        return ['clave' => $clave, 'nombre' => $nombre, 'ancho' => $ancho, 'alto' => $alto, 'escala' => self::escala($ancho, $alto)];
    }

    public static function anchoTicket(?SidesCfg $cfg): int
    {
        $ancho = (int) $cfg?->ticketAncho;

        return $ancho >= self::TICKET_MIN && $ancho <= self::TICKET_MAX ? $ancho : self::TICKET_ANCHO;
    }

    /**
     * Cuánto se agranda o achica el diseño de la etiqueta, hecho para 80 mm de alto. En una
     * etiqueta angosta manda el ancho: el diseño necesita unos 108 mm por unidad de escala (lo
     * que tiene la de 9,5 × 7 cm), así los cuatro formatos de lista quedan igual que siempre.
     */
    private static function escala(int $ancho, int $alto): float
    {
        return round(min($alto / 80, $ancho / 108), 3);
    }

    private static function medidaValida(int $mm): bool
    {
        return $mm >= self::MIN_MM && $mm <= self::MAX_MM;
    }

    private static function cm(int $mm): string
    {
        return str_replace('.', ',', (string) ($mm / 10));
    }
}
