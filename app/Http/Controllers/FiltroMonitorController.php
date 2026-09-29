<?php

namespace App\Http\Controllers;

use App\Models\Sides\SidesMonitor;
use App\Support\MenuSides;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Filtros con nombre para la pantalla de Monitor (legacy FiltromonitorController, tabla `monitor`
 * → `sides_monitor`). Porta dromarko/droactiva/mastranto (idénticos entre sí) con un cambio
 * deliberado: el legacy evaluaba `criterio` con `eval()` como código PHP para los filtros con
 * id 1/2 ("PLANTA ALTA"/"PLANTA BAJA"); acá `criterio` es siempre una lista de fragmentos de
 * ruta separados por coma (ya era así para cualquier id que no fuera 1 o 2 en el legacy), sin
 * `eval()` y sin ids reservados. El id ahora es un autoincremental normal.
 */
class FiltroMonitorController extends Controller
{
    public function index(Request $request): View
    {
        $filtros = SidesMonitor::query()
            ->where('codisb', $request->user()->codisb)
            ->orderBy('descrip')
            ->get();

        return view('filtromonitor.index', [
            'filtros' => $filtros,
            // El enlace "Ver en Monitor" solo si el usuario puede entrar al Monitor.
            'verMonitor' => MenuSides::puede($request->user(), $request->user()->cfg, 'monitor'),
        ]);
    }

    public function create(): View
    {
        return view('filtromonitor.form', ['filtro' => new SidesMonitor()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $request->validate($this->reglas(), $this->mensajes());

        $filtro = new SidesMonitor();
        $filtro->forceFill([...$datos, 'codisb' => $request->user()->codisb])->save();

        return redirect()->route('filtromonitor.index')->with('mensaje', "Filtro \"{$filtro->descrip}\" creado.");
    }

    public function edit(Request $request, int $filtro): View
    {
        return view('filtromonitor.form', ['filtro' => $this->buscar($request, $filtro)]);
    }

    public function update(Request $request, int $filtro): RedirectResponse
    {
        $registro = $this->buscar($request, $filtro);
        $datos = $request->validate($this->reglas(), $this->mensajes());

        $registro->forceFill($datos)->save();

        return redirect()->route('filtromonitor.index')->with('mensaje', "Filtro \"{$registro->descrip}\" actualizado.");
    }

    public function destroy(Request $request, int $filtro): RedirectResponse
    {
        $registro = $this->buscar($request, $filtro);
        $registro->delete();

        return redirect()->route('filtromonitor.index')->with('mensaje', "Filtro \"{$registro->descrip}\" eliminado.");
    }

    private function buscar(Request $request, int $id): SidesMonitor
    {
        return SidesMonitor::query()->where('codisb', $request->user()->codisb)->findOrFail($id);
    }

    private function reglas(): array
    {
        return [
            'descrip' => ['required', 'string', 'max:100'],
            'criterio' => ['required', 'string', 'max:100'],
            'caracterLogo' => ['nullable', 'string', 'max:10'],
        ];
    }

    private function mensajes(): array
    {
        return [
            'descrip.required' => 'Escribe un nombre para el filtro.',
            'descrip.max' => 'El nombre no puede pasar de 100 caracteres.',
            'criterio.required' => 'Escribe al menos un fragmento de ruta.',
            'criterio.max' => 'El criterio no puede pasar de 100 caracteres.',
            'caracterLogo.max' => 'El texto de la marca no puede pasar de 10 caracteres.',
        ];
    }
}
