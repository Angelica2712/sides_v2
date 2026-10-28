<?php

namespace App\Http\Controllers;

use App\Services\Almacen\PlanoAlmacen;
use App\Services\Almacen\UbicacionesRegistradas;
use App\Support\ConfigAlmacen;
use App\Support\UbicacionAlmacen;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Almacén 3D: los anaqueles de la droguería dibujados con las ubicaciones del catálogo (la
 * registrada en SIDES o la del ERP), para que el almacenista vea dónde está cada producto. El
 * formato de ubicación está en UbicacionAlmacen.
 */
class AlmacenController extends Controller
{
    public function __construct(private readonly PlanoAlmacen $plano)
    {
    }

    public function index(Request $request): View
    {
        return view('almacen.index', ['plano' => $this->plano->de($request->user()->codisb)]);
    }

    public function plano(Request $request): JsonResponse
    {
        return response()->json($this->plano->de($request->user()->codisb));
    }

    /** Productos del catálogo por código, barra o descripción, con su ubicación. */
    public function buscar(Request $request): JsonResponse
    {
        $buscar = trim((string) $request->query('q', ''));
        if (mb_strlen($buscar) < 2) {
            return response()->json(['productos' => []]);
        }

        $formato = ConfigAlmacen::formatoDe($request->user()->codisb);
        $productos = UbicacionesRegistradas::unir(DB::table('producto'), 'producto')
            ->where('producto.codisb', $request->user()->codisb)
            ->where(function ($filtro) use ($buscar) {
                $filtro->where('producto.codprod', $buscar)
                    ->orWhere('producto.barra', $buscar)
                    ->orWhere('producto.desprod', 'like', "%{$buscar}%");
            })
            ->orderBy('producto.desprod')
            ->limit(20)
            ->get(['producto.codprod', 'producto.desprod', DB::raw(UbicacionesRegistradas::sql('producto.ubicacion').' as ubicacion')])
            ->map(fn ($producto) => [
                'codprod' => (string) $producto->codprod,
                'desprod' => (string) $producto->desprod,
                'ubicacion' => (string) $producto->ubicacion,
                'ubicaciones' => array_column(UbicacionAlmacen::todas($producto->ubicacion, $formato), 'codigo'),
            ]);

        return response()->json(['productos' => $productos]);
    }
}
