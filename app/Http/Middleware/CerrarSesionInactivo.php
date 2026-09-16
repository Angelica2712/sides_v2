<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un usuario que pasa a INACTIVO (o se elimina) sale en su próxima petición, sin esperar a
 * que venza la sesión.
 */
class CerrarSesionInactivo
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if ($usuario && $usuario->estado !== 'ACTIVO') {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => 'Tu usuario está inactivo. Habla con el encargado de la sucursal.']);
        }

        return $next($request);
    }
}
