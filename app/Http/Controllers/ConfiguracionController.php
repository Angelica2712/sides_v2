<?php

namespace App\Http\Controllers;

use App\Models\Sides\SidesCfg;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Configuración de la sucursal del usuario (legacy AdminconfigController).
 *
 * Packing, los módulos opcionales y las opciones de etiquetas los define el administrador
 * (AdminController). No se portan los campos que en v2 ya no tienen efecto: dominio de la API
 * de SEPED (base compartida), ModoCesta, pitarPacking (el sonido del lector es por usuario),
 * EstiloPicking, título de la página y los datos de pie de etiqueta.
 */
class ConfiguracionController extends Controller
{
    public const TAMANOS_LETRA = [12, 14, 16, 18, 20, 22, 24, 26, 28, 30, 32, 34, 36, 38, 40, 42, 44, 50, 54, 64, 68, 74, 84];

    /** valor => etiqueta. ORIGINAL (o cualquier otro valor) = orden de los renglones del pedido. */
    public const ORDENES = [
        'ORIGINAL' => 'Como vienen en el pedido',
        'DESCRIPCION' => 'Por descripción',
        'UBICACION' => 'Por ubicación en el almacén',
        'MARCA' => 'Por marca',
    ];

    public const INTERRUPTORES = [
        'MostrarTituloMonitor', 'activarVerOperadorMonitor', 'mostrarObsMonitor', 'mostrarTranMonitor',
        'activarValPicking', 'mostrarExiRealPick', 'mostrarDepPiking',
        'activarValPacking', 'activar_separador_automatico',
    ];

    public function edit(Request $request): View
    {
        $cfg = $this->cfg($request);

        return view('configuracion.edit', [
            'cfg' => $cfg,
            'modulos' => $cfg->modulos()->where('activo', 1)->pluck('modulo')->all(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $cfg = $this->cfg($request);
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:150'],
            'nomcorto' => ['nullable', 'string', 'max:20'],
            'rif' => ['nullable', 'string', 'max:20'],
            'direccion' => ['nullable', 'string', 'max:150'],
            'localidad' => ['nullable', 'string', 'max:100'],
            'contacto' => ['nullable', 'string', 'max:50'],
            'telefono' => ['nullable', 'string', 'max:50'],
            'TamLetraMonitor' => ['required', 'integer', Rule::in(self::TAMANOS_LETRA)],
            'ordenPedSides' => ['required', Rule::in(array_keys(self::ORDENES))],
            'claveValPicking' => [
                Rule::requiredIf($request->boolean('activarValPicking') || $request->boolean('activarValPacking')),
                'nullable', 'string', 'min:4', 'max:20',
            ],
        ], [
            'nombre.required' => 'Escribe el nombre de la sucursal.',
            'TamLetraMonitor.*' => 'Elige un tamaño de letra de la lista.',
            'ordenPedSides.*' => 'Elige un orden de productos de la lista.',
            'claveValPicking.required' => 'Escribe la clave de supervisor: la piden Picking o Packing.',
            'claveValPicking.min' => 'La clave de supervisor debe tener al menos 4 caracteres.',
            'claveValPicking.max' => 'La clave de supervisor no puede pasar de 20 caracteres.',
            '*.max' => 'El campo :attribute es demasiado largo.',
        ], [
            'nomcorto' => 'nombre corto',
            'direccion' => 'dirección',
            'telefono' => 'teléfono',
        ]);

        foreach (self::INTERRUPTORES as $campo) {
            $datos[$campo] = $request->boolean($campo) ? 1 : 0;
        }
        // La casilla solo aparece con el módulo Rutas activo; si no, se conserva el valor.
        if ($cfg->tieneModulo('rutas')) {
            $datos['activarSincronizacionRutas'] = $request->boolean('activarSincronizacionRutas') ? 1 : 0;
        }
        // Si nadie la pide, se conserva la clave anterior en vez de dejarla vacía.
        $datos['claveValPicking'] = filled($datos['claveValPicking'] ?? null) ? $datos['claveValPicking'] : $cfg->claveValPicking;

        $cfg->forceFill($datos)->save();

        return redirect()->route('configuracion.index')->with('mensaje', 'Configuración guardada.');
    }

    private function cfg(Request $request): SidesCfg
    {
        return SidesCfg::query()->find($request->user()->codisb)
            ?? abort(404, 'Tu sucursal no tiene configuración en SIDES. Pide al administrador que la cree.');
    }
}
