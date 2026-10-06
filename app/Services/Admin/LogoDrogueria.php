<?php

namespace App\Services\Admin;

use App\Models\Sides\SidesCfg;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Logo de una droguería: sale en el encabezado de SIDES y en etiquetas, ticket y guías.
 * Lo sube solo el administrador (FULLTECH360). El archivo va al disco public con un nombre
 * nuevo en cada cambio, así el navegador y la impresora nunca muestran uno viejo de caché.
 */
class LogoDrogueria
{
    public const CARPETA = 'logos';

    /** Lado en píxeles del ícono de la pestaña (el navegador lo reduce a 16 o 32). */
    public const LADO_ICONO = 64;

    /** Formatos de imagen aceptados (reglas de validación). */
    public const REGLAS = ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048', 'dimensions:min_width=40,min_height=40'];

    public const MENSAJES = [
        'logo.image' => 'El logo debe ser una imagen.',
        'logo.mimes' => 'El logo debe ser PNG, JPG o WEBP.',
        'logo.max' => 'El logo no puede pesar más de 2 MB.',
        'logo.dimensions' => 'El logo es muy pequeño: debe medir al menos 40 × 40 píxeles.',
    ];

    /** Aplica el cambio pedido en el formulario: un logo nuevo, quitarlo o dejarlo igual. */
    public function aplicar(SidesCfg $cfg, ?UploadedFile $archivo, bool $quitar): void
    {
        if ($archivo) {
            $this->guardar($cfg, $archivo);
        } elseif ($quitar) {
            $this->quitar($cfg);
        }
        $this->icono($cfg);
    }

    /**
     * Ícono de la pestaña del navegador: una copia pequeña del logo con la forma elegida
     * (el navegador no recorta en círculo por su cuenta). Se crea si falta, así que al
     * cambiar de forma queda el nuevo y se borra el de la otra.
     */
    public function icono(SidesCfg $cfg): void
    {
        if (! $cfg->logo || ! str_starts_with($cfg->logo, self::CARPETA.'/')) {
            return;
        }
        $disco = Storage::disk('public');
        foreach (array_keys(SidesCfg::FORMAS_LOGO) as $forma) {
            if ($forma !== $cfg->formaLogo()) {
                $disco->delete(SidesCfg::rutaIcono($cfg->logo, $forma));
            }
        }
        $ruta = $cfg->rutaIconoActual();
        if ($disco->exists($ruta) || ! function_exists('imagecreatefromstring') || ! $disco->exists($cfg->logo)) {
            return;
        }
        if ($png = $this->dibujarIcono((string) $disco->get($cfg->logo), $cfg->logoCircular())) {
            $disco->put($ruta, $png);
        }
    }

    /** PNG cuadrado y transparente: el logo entero (cuadro) o llenando un círculo (círculo). */
    private function dibujarIcono(string $contenido, bool $circular): ?string
    {
        $origen = @imagecreatefromstring($contenido);
        if (! $origen) {
            return null;
        }
        $lado = self::LADO_ICONO;
        [$ancho, $alto] = [imagesx($origen), imagesy($origen)];
        $icono = imagecreatetruecolor($lado, $lado);
        imagealphablending($icono, false);
        imagesavealpha($icono, true);
        imagefill($icono, 0, 0, imagecolorallocatealpha($icono, 255, 255, 255, 127));

        if ($circular) {
            // Recorte centrado que llena el cuadrado; después se vacía lo que queda fuera del círculo.
            $corte = min($ancho, $alto);
            imagecopyresampled($icono, $origen, 0, 0, intdiv($ancho - $corte, 2), intdiv($alto - $corte, 2), $lado, $lado, $corte, $corte);
            $radio = $lado / 2;
            for ($y = 0; $y < $lado; $y++) {
                for ($x = 0; $x < $lado; $x++) {
                    $visible = max(0.0, min(1.0, $radio - hypot($x + 0.5 - $radio, $y + 0.5 - $radio) + 0.5));
                    if ($visible < 1.0) {
                        $color = imagecolorat($icono, $x, $y);
                        $alfa = 127 - (int) round((127 - (($color >> 24) & 0x7F)) * $visible);
                        imagesetpixel($icono, $x, $y, imagecolorallocatealpha($icono, ($color >> 16) & 0xFF, ($color >> 8) & 0xFF, $color & 0xFF, $alfa));
                    }
                }
            }
        } else {
            $escala = min($lado / $ancho, $lado / $alto);
            [$w, $h] = [max(1, (int) round($ancho * $escala)), max(1, (int) round($alto * $escala))];
            imagecopyresampled($icono, $origen, intdiv($lado - $w, 2), intdiv($lado - $h, 2), 0, 0, $w, $h, $ancho, $alto);
        }

        ob_start();
        imagepng($icono);

        return ob_get_clean() ?: null;
    }

    public function guardar(SidesCfg $cfg, UploadedFile $archivo): void
    {
        $anterior = $cfg->logo;
        $nombre = Str::slug($cfg->codisb).'-'.Str::lower(Str::random(8)).'.'.$archivo->extension();

        $cfg->forceFill(['logo' => $archivo->storeAs(self::CARPETA, $nombre, 'public')])->save();
        $this->borrar($anterior);
    }

    public function quitar(SidesCfg $cfg): void
    {
        $anterior = $cfg->logo;
        $cfg->forceFill(['logo' => null])->save();
        $this->borrar($anterior);
    }

    private function borrar(?string $ruta): void
    {
        if ($ruta && str_starts_with($ruta, self::CARPETA.'/')) {
            Storage::disk('public')->delete([$ruta, ...array_map(fn (string $forma) => SidesCfg::rutaIcono($ruta, $forma), array_keys(SidesCfg::FORMAS_LOGO))]);
        }
    }
}
