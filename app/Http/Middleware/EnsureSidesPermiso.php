<?php

namespace App\Http\Middleware;

use App\Support\MenuSides;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Uso: ->middleware('permiso:picking'). Aplica la misma regla que decide si el módulo
 * aparece en el menú (App\Support\MenuSides::puede).
 */
class EnsureSidesPermiso
{
    public function handle(Request $request, Closure $next, string $clave): Response
    {
        $usuario = $request->user();

        abort_unless(
            $usuario && MenuSides::puede($usuario, $usuario->cfg, $clave),
            403,
            'No tienes permiso para entrar a este módulo.'
        );

        return $next($request);
    }
}
