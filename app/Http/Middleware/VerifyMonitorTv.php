<?php

namespace App\Http\Middleware;

use App\Models\Sides\SidesMonitorTv;
use App\Services\MonitorTv\MonitorTvService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * API de los televisores (routes/api.php): `Authorization: Bearer {token}` que la TV recibió al
 * vincularse. Deja la TV en el atributo `monitor_tv` y su usuario como usuario de la petición
 * (sin sesión), así el picking en vivo, el plano y la firma del canal usan las mismas reglas
 * que la web. Cada acceso actualiza last_seen_at («conectada» en Televisores).
 */
class VerifyMonitorTv
{
    public function __construct(private readonly MonitorTvService $servicio) {}

    public function handle(Request $request, Closure $next): Response
    {
        $auth = $this->servicio->autenticar($request->bearerToken());

        if (! $auth) {
            $this->servicio->registrarFallo(null, $request->ip(), 401);

            return response()->json(['status' => 401, 'msg' => 'NO AUTORIZADO'], 401);
        }

        ['tv' => $tv, 'usuario' => $usuario] = $auth;

        SidesMonitorTv::query()->whereKey($tv->id)->update(['last_seen_at' => Carbon::now()]);

        Auth::setUser($usuario);
        $request->setUserResolver(fn () => $usuario);
        $request->attributes->set('monitor_tv', $tv);

        return $next($request);
    }
}
