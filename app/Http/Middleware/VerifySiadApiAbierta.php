<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** La API del SIAD sin token, como en el legacy. Solo con SIAD_API_ABIERTA=true (pruebas). */
class VerifySiadApiAbierta
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('siad.abierta'), 404);

        return $next($request);
    }
}
