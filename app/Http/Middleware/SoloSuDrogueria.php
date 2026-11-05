<?php

namespace App\Http\Middleware;

use App\Models\Sides\SidesWebhook;
use App\Models\Sides\SidesWebhookEntrega;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Uso: ->middleware('drogueria.propia') en las rutas de Administración. FULLTECH360 (esAdmin)
 * entra a todas las droguerías; quien tiene el permiso «Administración» solo a la suya. Otra
 * droguería da 404 (no 403), así no se sabe cuáles existen.
 */
class SoloSuDrogueria
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();
        $ruta = $request->route();

        $codisb = $ruta->parameter('codisb');
        $webhook = $ruta->parameter('webhook');
        $entrega = $ruta->parameter('entrega');

        // Los modelos todavía no están enlazados cuando corre este middleware: llegan como id.
        if ($webhook !== null) {
            $codisb = ($webhook instanceof SidesWebhook ? $webhook : SidesWebhook::query()->find($webhook))?->codisb;
        } elseif ($entrega !== null) {
            $codisb = ($entrega instanceof SidesWebhookEntrega ? $entrega : SidesWebhookEntrega::query()->find($entrega))?->webhook?->codisb;
        }

        if ($codisb !== null || $webhook !== null || $entrega !== null) {
            abort_unless($usuario && $codisb !== null && $usuario->administraDrogueria((string) $codisb), 404);
        }

        return $next($request);
    }
}
