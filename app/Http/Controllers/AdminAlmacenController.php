<?php

namespace App\Http\Controllers;

use App\Models\Sides\SidesAlmacenCfg;
use App\Models\Sides\SidesCfg;
use App\Services\Almacen\PlanoAlmacen;
use App\Support\ConfigAlmacen;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Administración del Almacén 3D de cada droguería (solo FULLTECH360): cómo está acomodado el
 * almacén cuando no sigue el acomodo recomendado. El código de ubicación no cambia; cambia dónde
 * queda físicamente cada número (ConfigAlmacen).
 */
class AdminAlmacenController extends Controller
{
    public function __construct(private readonly PlanoAlmacen $plano)
    {
    }

    public function edit(string $codisb): View
    {
        $drogueria = SidesCfg::query()->findOrFail($codisb);
        $plano = $this->plano->de($codisb);

        return view('admin.almacen', [
            'drogueria' => $drogueria,
            'plano' => $plano,
            // Se pueden renombrar las zonas de SIDES y las que ya aparecen en el catálogo.
            'zonas' => collect(ConfigAlmacen::ZONAS)
                ->merge(collect($plano['zonas'])->pluck('zona')->mapWithKeys(fn ($zona) => [$zona => ConfigAlmacen::ZONAS[$zona] ?? '']))
                ->sortKeys()
                ->all(),
        ]);
    }

    public function update(Request $request, string $codisb): RedirectResponse
    {
        SidesCfg::query()->findOrFail($codisb);

        $datos = $request->validate([
            'numeracion' => ['required', Rule::in(array_keys(ConfigAlmacen::NUMERACIONES))],
            'estanteA' => ['required', Rule::in(array_keys(ConfigAlmacen::ESTANTE_A))],
            'posicion1' => ['required', Rule::in(array_keys(ConfigAlmacen::POSICION_1))],
            'estantes' => ['required', 'integer', 'min:1', 'max:12'],
            'posiciones' => ['required', 'integer', 'min:1', 'max:30'],
            'zonas' => ['array'],
            'zonas.*' => ['nullable', 'string', 'max:40'],
            // Lo arma el editor del plano: {"PK-03-12": {"x": 1.5, "z": 4, "giro": 90}, ...}
            'disposicion' => ['nullable', 'json', 'max:500000'],
        ], [], [
            'numeracion' => 'numeración de anaqueles',
            'estanteA' => 'estante A',
            'posicion1' => 'posición 1',
            'estantes' => 'estantes por anaquel',
            'posiciones' => 'posiciones por estante',
            'disposicion' => 'ubicación de los anaqueles',
        ]);
        $datos['disposicion'] = json_decode($datos['disposicion'] ?? '{}', true) ?: [];

        SidesAlmacenCfg::query()->updateOrCreate(
            ['codisb' => $codisb],
            ['config' => ConfigAlmacen::normalizar($datos), 'actualizado_por' => $request->user()->email],
        );

        return redirect()->route('admin.almacen.edit', $codisb)->with('mensaje', 'Guardaste el acomodo del almacén.');
    }

    public function restablecer(string $codisb): RedirectResponse
    {
        SidesAlmacenCfg::query()->whereKey($codisb)->delete();

        return redirect()->route('admin.almacen.edit', $codisb)->with('mensaje', 'El almacén volvió al acomodo recomendado.');
    }
}
