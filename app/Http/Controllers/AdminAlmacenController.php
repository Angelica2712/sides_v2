<?php

namespace App\Http\Controllers;

use App\Models\Sides\SidesCfg;
use App\Services\Almacen\AcomodoAlmacen;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Administración del Almacén 3D de cada droguería (FULLTECH360): cómo está acomodado el
 * almacén cuando no sigue el acomodo recomendado. El código de ubicación no cambia; cambia dónde
 * queda físicamente cada número (ConfigAlmacen). La droguería edita el suyo desde Ubicaciones.
 */
class AdminAlmacenController extends Controller
{
    public function __construct(private readonly AcomodoAlmacen $acomodo)
    {
    }

    public function edit(string $codisb): View
    {
        $drogueria = SidesCfg::query()->findOrFail($codisb);

        return view('almacen.acomodo', [
            ...$this->acomodo->pantalla($codisb, conQuitados: true),
            'titulo' => 'Almacén de '.($drogueria->nombre ?: $drogueria->codisb),
            'volver' => [route('admin.index'), 'Volver a droguerías'],
            'urls' => [
                'guardar' => route('admin.almacen.update', $codisb),
                'restablecer' => route('admin.almacen.restablecer', $codisb),
            ],
        ]);
    }

    public function update(Request $request, string $codisb): RedirectResponse
    {
        SidesCfg::query()->findOrFail($codisb);
        $this->acomodo->guardar($request, $codisb);

        return redirect()->route('admin.almacen.edit', $codisb)->with('mensaje', 'Guardaste el acomodo del almacén.');
    }

    public function restablecer(string $codisb): RedirectResponse
    {
        $this->acomodo->restablecer($codisb);

        return redirect()->route('admin.almacen.edit', $codisb)->with('mensaje', 'El almacén volvió al acomodo recomendado.');
    }
}
