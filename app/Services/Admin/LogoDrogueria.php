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
            Storage::disk('public')->delete($ruta);
        }
    }
}
