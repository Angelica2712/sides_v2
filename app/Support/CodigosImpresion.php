<?php

namespace App\Support;

use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Picqer\Barcode\BarcodeGeneratorSVG;

/** Código de barras y QR como SVG en línea, para páginas de impresión (el tamaño lo da el CSS). */
class CodigosImpresion
{
    public static function barras(string $codigo): string
    {
        $svg = (new BarcodeGeneratorSVG())->getBarcode($codigo, BarcodeGeneratorSVG::TYPE_CODE_128, 2, 60);

        return self::incrustable($svg, 'preserveAspectRatio="none"');
    }

    public static function qr(string $texto): string
    {
        $opciones = new QROptions([
            'outputInterface' => QRMarkupSVG::class,
            'outputBase64' => false,
            'drawLightModules' => false,
            'addQuietzone' => false,
        ]);

        return self::incrustable((new QRCode($opciones))->render($texto));
    }

    /** Quita el prólogo XML y el ancho/alto fijos del <svg> raíz para poder insertarlo en el HTML. */
    private static function incrustable(string $svg, string $atributos = ''): string
    {
        $svg = substr($svg, (int) strpos($svg, '<svg'));

        return preg_replace_callback('/^<svg\b[^>]*>/', function (array $raiz) use ($atributos) {
            $etiqueta = preg_replace('/\s(width|height)="[^"]*"/', '', $raiz[0]);

            return substr($etiqueta, 0, -1).' aria-hidden="true" '.$atributos.'>';
        }, $svg, 1);
    }
}
