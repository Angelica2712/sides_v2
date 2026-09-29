<?php

namespace App\Http\Middleware;

use App\Services\Auditoria\Auditoria;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Registra en la auditoría cada acción que cambia algo (ver App\Services\Auditoria\Auditoria). */
class RegistrarAuditoria
{
    public function __construct(private readonly Auditoria $auditoria)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $respuesta = $next($request);

        if (! $request->isMethodSafe()) {
            $this->auditoria->desdePeticion($request, $respuesta);
        }

        return $respuesta;
    }
}
