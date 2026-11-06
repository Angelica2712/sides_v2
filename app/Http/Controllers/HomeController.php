<?php

namespace App\Http\Controllers;

use App\Services\Inicio\DesempenoAlmacen;
use App\Support\MenuSides;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __invoke(Request $request): View|RedirectResponse
    {
        $usuario = $request->user();
        $cfg = $usuario->cfg;
        $modulos = MenuSides::visibles($usuario, $cfg);

        // El televisor («Pantalla de TV») no tiene módulos en el menú: entra directo a su pantalla.
        // El jefe con solo «Indicadores del almacén» tampoco tiene módulos, pero se queda en el Inicio.
        if (! $modulos && ! self::veIndicadores($request) && MenuSides::puede($usuario, $cfg, 'pickingvivo')) {
            return redirect()->route('monitor.picking');
        }

        $conVivo = MenuSides::puede($usuario, $cfg, 'pickingvivo');

        return view('home', [
            'cfg' => $cfg,
            'modulos' => $modulos,
            'secciones' => MenuSides::porSeccion($usuario, $cfg),
            'conIndicadores' => self::veIndicadores($request),
            'periodos' => DesempenoAlmacen::PERIODOS,
            'vivo' => $conVivo ? [
                'datos' => route('monitor.picking.datos'),
                'plano' => MenuSides::puede($usuario, $cfg, 'mapavivo') ? route('monitor.picking.plano') : null,
            ] : null,
        ]);
    }

    /** Tiempos promedio, cola y desempeño del almacén (los pide y refresca resources/js/inicio.js). */
    public function indicadores(Request $request, DesempenoAlmacen $desempeno): JsonResponse
    {
        abort_unless(self::veIndicadores($request), 403);
        $usuario = $request->user();

        return response()->json($desempeno->de(
            $usuario->codisb,
            DesempenoAlmacen::periodo($request->query('periodo')),
            (bool) ($usuario->cfg?->activarPacking ?? true),
        ));
    }

    /** Los números de toda la droguería son para el jefe de operaciones (permiso «Indicadores del almacén»). */
    private static function veIndicadores(Request $request): bool
    {
        $usuario = $request->user();

        return MenuSides::puede($usuario, $usuario->cfg, 'indicadores');
    }
}
