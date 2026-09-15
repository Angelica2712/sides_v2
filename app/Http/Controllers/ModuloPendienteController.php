<?php

namespace App\Http\Controllers;

use App\Support\MenuSides;
use Illuminate\View\View;

/**
 * Página temporal de los módulos que aún no se portan del legacy. Al portar un módulo,
 * su ruta en routes/web.php pasa a apuntar a su controlador real.
 */
class ModuloPendienteController extends Controller
{
    public function __invoke(string $clave): View
    {
        return view('modulo-pendiente', ['modulo' => MenuSides::modulo($clave)]);
    }
}
