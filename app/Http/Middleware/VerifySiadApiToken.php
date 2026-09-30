<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Entrada de la API del SIAD (/api/siad/{token}/get_pedido...). Compara el token con la lista
 * de config/siad.php con hash_equals y falla cerrado: sin tokens configurados no entra nadie.
 * Responde 404 y no 401 para no delatar que la ruta existe.
 */
class VerifySiadApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->route('token');

        foreach ((array) config('siad.tokens', []) as $configurado) {
            if ($configurado !== '' && hash_equals((string) $configurado, $token)) {
                // El controlador no lo recibe, y así tampoco llega a ningún log.
                $request->route()->forgetParameter('token');

                return $next($request);
            }
        }

        abort(404);
    }
}
