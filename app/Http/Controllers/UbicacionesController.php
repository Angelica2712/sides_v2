<?php

namespace App\Http\Controllers;

use App\Services\Almacen\AcomodoAlmacen;
use App\Services\Almacen\UbicacionesRegistradas;
use App\Support\UbicacionAlmacen;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Pestañas del Almacén 3D para que la droguería numere su almacén con el formato de SIDES. Quien
 * tiene el permiso (activarUbicaciones) o administra la droguería (activarConfig) recorre el
 * almacén con el teléfono y le escribe a cada producto su ubicación (o lo arrastra en el 3D, que
 * guarda con este mismo controlador), y registra los pasillos y anaqueles en el acomodo. La
 * ubicación registrada gana sobre la del ERP (UbicacionesRegistradas).
 */
class UbicacionesController extends Controller
{
    public function __construct(
        private readonly UbicacionesRegistradas $ubicaciones,
        private readonly AcomodoAlmacen $acomodo,
    ) {
    }

    public function index(Request $request): View
    {
        $usuario = $request->user();
        $pantalla = $this->acomodo->pantalla($usuario->codisb);

        return view('almacen.ubicaciones', [
            'datos' => [
                'plano' => $pantalla['plano'],
                'zonas' => collect($pantalla['zonas'])
                    ->map(fn ($nombre, $codigo) => $pantalla['plano']['config']['zonas'][$codigo] ?? ($nombre ?: $codigo))
                    ->all(),
                'recientes' => $this->recientes($usuario->codisb),
                // Desde el Almacén 3D se llega con el producto ya buscado.
                'buscar' => mb_substr(trim((string) $request->query('q', '')), 0, 100),
                'urls' => [
                    'buscar' => route('almacen.ubicaciones.buscar'),
                    'guardar' => route('almacen.ubicaciones.guardar'),
                    'quitar' => route('almacen.ubicaciones.quitar'),
                ],
            ],
        ]);
    }

    /** Productos del catálogo por código, barra o descripción, con la ubicación del ERP y la de SIDES. */
    public function buscar(Request $request): JsonResponse
    {
        $buscar = trim((string) $request->query('q', ''));
        if (mb_strlen($buscar) < 2) {
            return response()->json(['productos' => []]);
        }

        $productos = $this->consulta($request->user()->codisb)
            ->where(function ($filtro) use ($buscar) {
                $filtro->where('producto.codprod', $buscar)
                    ->orWhere('producto.barra', $buscar)
                    ->orWhere('producto.desprod', 'like', "%{$buscar}%");
            })
            // El código o la barra exactos primero: con el lector se elige solo.
            ->orderByRaw('CASE WHEN producto.codprod = ? OR producto.barra = ? THEN 0 ELSE 1 END', [$buscar, $buscar])
            ->orderBy('producto.desprod')
            ->limit(20)
            ->get()
            ->map(fn ($producto) => $this->producto($producto));

        return response()->json([
            'productos' => $productos,
            'exacto' => $productos->contains(fn ($p) => $p['codprod'] === $buscar || $p['barra'] === $buscar),
        ]);
    }

    public function guardar(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'codprod' => ['required', 'string', 'max:50'],
            'ubicacion' => ['required', 'string', 'max:255'],
        ], [
            'ubicacion.required' => 'Escribe la ubicación del producto.',
        ]);
        $codisb = $request->user()->codisb;
        $this->existe($codisb, $datos['codprod']);

        $codigos = [];
        foreach (explode(';', $datos['ubicacion']) as $parte) {
            if (trim($parte) === '') {
                continue;
            }
            $ubicacion = UbicacionAlmacen::interpretar($parte);
            if (! $ubicacion) {
                throw ValidationException::withMessages([
                    'ubicacion' => 'La ubicación «'.trim($parte).'» no sigue el formato ZONA-PASILLO-ANAQUEL-ESTANTE-POSICIÓN (por ejemplo PK-03-12-B-04).',
                ]);
            }
            $codigos[] = $ubicacion['codigo'];
        }
        if (! $codigos) {
            throw ValidationException::withMessages(['ubicacion' => 'Escribe la ubicación del producto.']);
        }

        $this->ubicaciones->guardar($codisb, $datos['codprod'], implode(';', array_unique($codigos)), $request->user());

        return $this->respuesta($codisb, $datos['codprod'], 'Ubicación guardada.');
    }

    /** Quita la ubicación registrada en SIDES: el producto vuelve a la del ERP. */
    public function quitar(Request $request): JsonResponse
    {
        $datos = $request->validate(['codprod' => ['required', 'string', 'max:50']]);
        $codisb = $request->user()->codisb;
        $this->existe($codisb, $datos['codprod']);

        $this->ubicaciones->quitar($codisb, $datos['codprod']);

        return $this->respuesta($codisb, $datos['codprod'], 'Se quitó la ubicación de SIDES: el producto vuelve a la del ERP.');
    }

    public function acomodo(Request $request): View
    {
        return view('almacen.acomodo', [
            ...$this->acomodo->pantalla($request->user()->codisb, conQuitados: true),
            'titulo' => 'Ajustes avanzados del almacén',
            'pestana' => 'almacen.acomodo',
            'urls' => [
                'guardar' => route('almacen.acomodo.update'),
                'restablecer' => route('almacen.acomodo.restablecer'),
            ],
        ]);
    }

    public function guardarAcomodo(Request $request): RedirectResponse
    {
        $this->acomodo->guardar($request, $request->user()->codisb);

        return redirect()->route('almacen.acomodo')->with('mensaje', 'Guardaste el acomodo del almacén.');
    }

    public function restablecerAcomodo(Request $request): RedirectResponse
    {
        $this->acomodo->restablecer($request->user()->codisb);

        return redirect()->route('almacen.acomodo')->with('mensaje', 'El almacén volvió al acomodo recomendado.');
    }

    private function consulta(string $codisb)
    {
        return UbicacionesRegistradas::unir(DB::table('producto'), 'producto')
            ->where('producto.codisb', $codisb)
            ->select([
                'producto.codprod', 'producto.desprod', 'producto.barra', 'producto.ubicacion as erp',
                'su.ubicacion as sides', 'su.usuario', 'su.updated_at',
            ]);
    }

    private function existe(string $codisb, string $codprod): void
    {
        if (! DB::table('producto')->where('codisb', $codisb)->where('codprod', $codprod)->exists()) {
            throw ValidationException::withMessages(['codprod' => 'El producto no está en el catálogo de la droguería.']);
        }
    }

    private function respuesta(string $codisb, string $codprod, string $mensaje): JsonResponse
    {
        return response()->json([
            'mensaje' => $mensaje,
            'producto' => $this->producto($this->consulta($codisb)->where('producto.codprod', $codprod)->first()),
            'recientes' => $this->recientes($codisb),
        ]);
    }

    private function producto(object $producto): array
    {
        return [
            'codprod' => (string) $producto->codprod,
            'desprod' => (string) $producto->desprod,
            'barra' => (string) $producto->barra,
            'erp' => (string) $producto->erp,
            'sides' => (string) $producto->sides,
            'usuario' => (string) $producto->usuario,
            'fecha' => $producto->updated_at ? Carbon::parse($producto->updated_at)->format('d/m/Y H:i') : '',
        ];
    }

    private function recientes(string $codisb): array
    {
        return $this->ubicaciones->recientes($codisb)
            ->map(fn ($fila) => [
                'codprod' => (string) $fila->codprod,
                'desprod' => (string) $fila->desprod,
                'ubicacion' => (string) $fila->ubicacion,
                'usuario' => (string) $fila->usuario,
                'fecha' => $fila->updated_at ? Carbon::parse($fila->updated_at)->format('d/m/Y H:i') : '',
            ])
            ->values()
            ->all();
    }
}
