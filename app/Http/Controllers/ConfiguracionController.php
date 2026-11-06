<?php

namespace App\Http\Controllers;

use App\Models\Sides\SidesCfg;
use App\Models\Sides\SidesModuloSucursal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Configuración de la sucursal del usuario (legacy AdminconfigController).
 *
 * Packing, los módulos opcionales, las opciones de etiquetas y los datos de la droguería
 * (nombre, RIF, dirección, logo…) los define el administrador de FULLTECH360 (AdminController). No se portan los campos que en v2 ya no tienen efecto: dominio de la API
 * de SEPED (base compartida), ModoCesta, pitarPacking (el sonido del lector es por usuario),
 * EstiloPicking, título de la página y los datos de pie de etiqueta.
 *
 * El módulo Etiquetas es el único opcional que la droguería enciende o apaga aquí por su cuenta.
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

    /**
     * Parámetros de la vista «Alertas» de los televisores (MonitorAlertasService): campo =>
     * [mínimo, máximo, etiqueta, ayuda]. Los límites en minutos y la tolerancia aceptan 0 = apagado.
     */
    public const ALERTAS = [
        'alertaLimiteEsperaMin' => [0, 10080, 'Límite sin pasar a picking (min)', '0 = no se avisa.'],
        'alertaLimitePickingMin' => [0, 10080, 'Límite en picking (min)', '0 = no se avisa.'],
        'alertaLimitePackingMin' => [0, 10080, 'Límite en packing (min)', '0 = no se avisa.'],
        'alertaTolerancia' => [0, 1000, 'Tolerancia sobre el tiempo esperado (%)', 'Retraso si pasa del tiempo esperado por las unidades del pedido más este %. 0 = no se usa.'],
        'alertaUmbralAviso' => [1, 1000, 'Rendimiento: amarillo desde (%)', 'Más lento que su propio promedio en este %.'],
        'alertaUmbralAlerta' => [1, 1000, 'Rendimiento: rojo desde (%)', 'Tiene que ser mayor que el amarillo.'],
        'alertaVentanaDias' => [1, 365, 'Días de historial para los promedios', 'Entre 1 y 365.'],
        'alertaMuestraMinima' => [1, 1000, 'Pedidos mínimos de historial', 'Con menos, no se compara a nadie.'],
        'alertaMuestraMinimaHoy' => [1, 100, 'Pedidos mínimos de hoy', 'Para comparar lo que va del día.'],
    ];

    public const INTERRUPTORES = [
        'MostrarTituloMonitor', 'activarVerOperadorMonitor', 'mostrarObsMonitor', 'mostrarTranMonitor',
        'activarValPicking', 'pickingOrdenLibre', 'mostrarExiRealPick', 'mostrarDepPiking',
        'activarValPacking', 'activar_separador_automatico',
        'pickingSiguienteParte',
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
        // `sometimes`: una pantalla que no los manda conserva los valores guardados.
        $alertas = collect(self::ALERTAS)->map(fn ($regla) => ['sometimes', 'required', 'integer', "min:{$regla[0]}", "max:{$regla[1]}"])->all();
        $alertas['alertaUmbralAlerta'][] = 'gt:alertaUmbralAviso';
        // El campo viaja aunque esté oculto: si nadie pide la clave no se valida, se conserva la guardada.
        $usaClave = $request->boolean('activarValPicking') || $request->boolean('activarValPacking');

        $datos = $request->validate([
            ...$alertas,
            'TamLetraMonitor' => ['required', 'integer', Rule::in(self::TAMANOS_LETRA)],
            'ordenPedSides' => ['required', Rule::in(array_keys(self::ORDENES))],
            'claveValPicking' => $usaClave ? ['required', 'string', 'min:4', 'max:20'] : ['nullable'],
        ], [
            'TamLetraMonitor.*' => 'Elige un tamaño de letra de la lista.',
            'alertaUmbralAlerta.gt' => 'En las alertas de los televisores, el rojo tiene que empezar en un % mayor que el amarillo.',
            'alerta*.*' => 'Revisa los números de las alertas de los televisores: cada uno tiene su mínimo y su máximo.',
            'ordenPedSides.*' => 'Elige un orden de productos de la lista.',
            'claveValPicking.required' => 'Escribe la clave de supervisor: la piden Picking o Packing.',
            'claveValPicking.min' => 'La clave de supervisor debe tener al menos 4 caracteres.',
            'claveValPicking.max' => 'La clave de supervisor no puede pasar de 20 caracteres.',
        ]);

        foreach (self::INTERRUPTORES as $campo) {
            $datos[$campo] = $request->boolean($campo) ? 1 : 0;
        }
        // La casilla solo aparece con el módulo Rutas activo; si no, se conserva el valor.
        if ($cfg->tieneModulo('rutas')) {
            $datos['activarSincronizacionRutas'] = $request->boolean('activarSincronizacionRutas') ? 1 : 0;
        }
        // Si nadie la pide, se conserva la clave anterior en vez de dejarla vacía.
        $datos['claveValPicking'] = $usaClave ? $datos['claveValPicking'] : $cfg->claveValPicking;

        // Etiquetas: la droguería lo enciende o apaga sola (igual que lo guarda Administración).
        $etiquetas = $request->boolean('moduloEtiquetas');
        $datos['activarEtiPacking'] = $etiquetas ? 1 : 0;
        if (! $etiquetas) {
            $datos['activar_etiqueta_packing'] = 0;
        }

        DB::transaction(function () use ($cfg, $datos, $etiquetas, $request) {
            $cfg->forceFill($datos)->save();
            SidesModuloSucursal::query()->updateOrCreate(
                ['codisb' => $cfg->codisb, 'modulo' => 'etiquetas'],
                ['activo' => $etiquetas ? 1 : 0, 'actualizado_por' => $request->user()->name, 'updated_at' => Carbon::now()]
            );
        });

        return redirect()->route('configuracion.index')->with('mensaje', 'Configuración guardada.');
    }

    private function cfg(Request $request): SidesCfg
    {
        return SidesCfg::query()->find($request->user()->codisb)
            ?? abort(404, 'Tu sucursal no tiene configuración en SIDES. Pide al administrador que la cree.');
    }
}
