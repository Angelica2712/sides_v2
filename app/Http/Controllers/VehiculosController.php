<?php

namespace App\Http\Controllers;

use App\Models\Sides\SidesVehiculo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Carros de la droguería para las guías de despacho. No se borran, se desactivan: las guías viejas
 * siguen apuntando a ellos.
 */
class VehiculosController extends Controller
{
    public function index(Request $request): View
    {
        return view('guias.vehiculos', [
            'vehiculos' => SidesVehiculo::query()->where('codisb', $request->user()->codisb)
                ->orderByDesc('activo')->orderBy('placa')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        SidesVehiculo::query()->create(['codisb' => $request->user()->codisb, 'activo' => 1] + $this->validar($request));

        return redirect()->route('vehiculos.index')->with('mensaje', 'Carro agregado.');
    }

    public function update(Request $request, int $vehiculo): RedirectResponse
    {
        $registro = SidesVehiculo::query()->where('codisb', $request->user()->codisb)->findOrFail($vehiculo);

        $registro->update($this->validar($request, $registro) + ['activo' => $request->boolean('activo') ? 1 : 0]);

        return redirect()->route('vehiculos.index')->with('mensaje', "Carro {$registro->placa} actualizado.");
    }

    /** @return array{placa: string, descripcion: ?string} */
    private function validar(Request $request, ?SidesVehiculo $vehiculo = null): array
    {
        $request->merge(['placa' => mb_strtoupper(trim((string) $request->input('placa')))]);

        $datos = $request->validate([
            'placa' => [
                'required', 'string', 'max:20',
                Rule::unique('sides_vehiculo', 'placa')->where('codisb', $request->user()->codisb)->ignore($vehiculo?->id),
            ],
            'descripcion' => ['nullable', 'string', 'max:70'],
        ], [
            'placa.required' => 'Escribe la placa del carro.',
            'placa.max' => 'La placa no puede pasar de 20 caracteres.',
            'placa.unique' => 'Ya hay un carro con esa placa.',
            'descripcion.max' => 'La descripción no puede pasar de 70 caracteres.',
        ]);

        return ['placa' => $datos['placa'], 'descripcion' => filled($datos['descripcion'] ?? null) ? trim($datos['descripcion']) : null];
    }
}
