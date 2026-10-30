<?php

namespace App\Http\Controllers;

use App\Models\Sides\SidesCesta;
use App\Services\Picking\CestasService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Lista de cestas de la droguería: el picking solo acepta las que están aquí y libres. */
class CestasController extends Controller
{
    private const MAX_POR_CARGA = 1000;

    public function __construct(private readonly CestasService $cestas)
    {
    }

    public function index(Request $request): View
    {
        $codisb = $request->user()->codisb;

        return view('configuracion.cestas', [
            'cestas' => SidesCesta::query()->where('codisb', $codisb)->orderByDesc('activo')->orderBy('codigo')->get(),
            'ocupadas' => $this->cestas->ocupadas($codisb),
        ]);
    }

    /** Agrega las escritas una por línea y, si viene, un rango (CESTA + 1 a 50 = CESTA 1 … CESTA 50). */
    public function store(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'lista' => ['nullable', 'string'],
            'prefijo' => ['nullable', 'string', 'max:40'],
            'desde' => ['nullable', 'integer', 'min:0', 'required_with:hasta'],
            'hasta' => ['nullable', 'integer', 'gte:desde', 'required_with:desde'],
        ], [
            'desde.required_with' => 'Escribe desde qué número.',
            'hasta.required_with' => 'Escribe hasta qué número.',
            'hasta.gte' => 'El número final no puede ser menor que el inicial.',
            '*.integer' => 'El rango debe ser de números enteros.',
        ]);

        $codigos = preg_split('/\R/u', (string) ($datos['lista'] ?? ''));
        if (isset($datos['desde']) && $datos['hasta'] - $datos['desde'] < self::MAX_POR_CARGA) {
            $prefijo = trim((string) ($datos['prefijo'] ?? ''));
            foreach (range($datos['desde'], $datos['hasta']) as $numero) {
                $codigos[] = trim("{$prefijo} {$numero}");
            }
        }

        $codigos = collect($codigos)->map(fn ($codigo) => CestasService::normalizar($codigo))->filter()->unique()->values();

        if ($codigos->isEmpty() || $codigos->count() > self::MAX_POR_CARGA) {
            throw ValidationException::withMessages(['lista' => $codigos->isEmpty()
                ? 'Escribe al menos una cesta.'
                : 'Son demasiadas cestas de una vez: máximo '.self::MAX_POR_CARGA.'.']);
        }

        if ($largo = $codigos->first(fn ($codigo) => mb_strlen($codigo) > 50)) {
            throw ValidationException::withMessages(['lista' => "El código {$largo} pasa de 50 caracteres."]);
        }

        $codisb = $request->user()->codisb;
        $existentes = SidesCesta::query()->where('codisb', $codisb)->pluck('codigo')->map(fn ($c) => CestasService::normalizar($c));
        $nuevos = $codigos->diff($existentes);

        foreach ($nuevos as $codigo) {
            SidesCesta::query()->create(['codisb' => $codisb, 'codigo' => $codigo, 'activo' => 1]);
        }

        $repetidas = $codigos->count() - $nuevos->count();

        return redirect()->route('cestas.index')->with('mensaje', "Se agregaron {$nuevos->count()} cestas.".($repetidas ? " {$repetidas} ya estaban en la lista." : ''));
    }

    /** Activar o desactivar. Una desactivada no se puede tomar; la que ya esté en uso sigue hasta terminar. */
    public function update(Request $request, int $cesta): RedirectResponse
    {
        $registro = SidesCesta::query()->where('codisb', $request->user()->codisb)->findOrFail($cesta);
        $registro->update(['activo' => $request->boolean('activo') ? 1 : 0]);

        return redirect()->route('cestas.index')->with('mensaje', "Cesta {$registro->codigo} ".($registro->activo ? 'activada.' : 'desactivada.'));
    }

    public function destroy(Request $request, int $cesta): RedirectResponse
    {
        $codisb = $request->user()->codisb;
        $registro = SidesCesta::query()->where('codisb', $codisb)->findOrFail($cesta);

        if ($this->cestas->ocupadas($codisb)->has(CestasService::normalizar($registro->codigo))) {
            return redirect()->route('cestas.index')->with('error', "La cesta {$registro->codigo} está en uso: no se puede borrar.");
        }

        $registro->delete();

        return redirect()->route('cestas.index')->with('mensaje', "Cesta {$registro->codigo} borrada.");
    }
}
