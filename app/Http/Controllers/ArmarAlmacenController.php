<?php

namespace App\Http\Controllers;

use App\Services\Almacen\AcomodoAlmacen;
use App\Support\ConfigAlmacen;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Almacén 3D > Armar almacén: asistente para el teléfono. Quien numera el almacén lo camina
 * pasillo por pasillo y responde lo que ve (anaqueles a cada lado, estantes, huecos); SIDES
 * numera con su formato y cada pasillo se guarda al terminarlo (AcomodoAlmacen::guardarPasillo).
 */
class ArmarAlmacenController extends Controller
{
    public function __construct(private readonly AcomodoAlmacen $acomodo)
    {
    }

    public function index(Request $request): View
    {
        $config = ConfigAlmacen::de($request->user()->codisb);

        return view('almacen.armar', [
            'datos' => [
                'zonas' => collect(ConfigAlmacen::ZONAS)->merge($config['zonas'])->all(),
                'pasillos' => $this->pasillos($config),
                'medidas' => ['estantes' => $config['estantes'], 'posiciones' => $config['posiciones']],
                'maximoPorLado' => ConfigAlmacen::MAXIMO_POR_LADO,
                // El asistente deja la numeración de SIDES: se avisa si el almacén tenía otra.
                'otraNumeracion' => array_intersect_key($config, ConfigAlmacen::NUMERACION_SIDES) != ConfigAlmacen::NUMERACION_SIDES,
                'urls' => [
                    'guardar' => route('almacen.armar.guardar'),
                    'quitar' => route('almacen.armar.quitar'),
                ],
            ],
        ]);
    }

    public function guardar(Request $request): JsonResponse
    {
        $config = $this->acomodo->guardarPasillo($request, $request->user()->codisb);

        return response()->json([
            'pasillos' => $this->pasillos($config),
            'zonas' => collect(ConfigAlmacen::ZONAS)->merge($config['zonas'])->all(),
        ]);
    }

    public function quitar(Request $request): JsonResponse
    {
        $config = $this->acomodo->quitarPasillo($request, $request->user()->codisb);

        return response()->json(['pasillos' => $this->pasillos($config)]);
    }

    /** Pasillos registrados como lista, con las medidas generales donde el pasillo no dijo las suyas. */
    private function pasillos(array $config): array
    {
        return collect($config['pasillos'])
            ->map(fn (array $pasillo, string $clave) => [
                'clave' => $clave,
                'zona' => substr($clave, 0, 2),
                'pasillo' => (int) substr($clave, 3),
                'izquierda' => $pasillo['izquierda'],
                'derecha' => $pasillo['derecha'],
                'estantes' => $pasillo['estantes'] ?? $config['estantes'],
                'posiciones' => $pasillo['posiciones'] ?? $config['posiciones'],
            ])
            ->values()
            ->all();
    }
}
