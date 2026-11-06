<?php

namespace App\Providers;

use App\Models\Sides\SidesUsers;
use App\Services\Auditoria\Auditoria;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // API de los televisores: el límite es por TV (su token), no por IP, porque todas las TVs
        // de una droguería salen a internet por la misma IP. 240 por minuto = 4 por segundo.
        RateLimiter::for('monitor-tv', fn (Request $request) => Limit::perMinute(240)
            ->by('monitor-tv:'.hash('sha256', (string) $request->bearerToken()))
            ->response(fn () => response()->json(['status' => 429, 'msg' => 'DEMASIADAS SOLICITUDES'], 429)));

        // Auditoría de sesiones: entradas, salidas e intentos fallidos (sin guardar la contraseña).
        Event::listen(Login::class, fn (Login $evento) => app(Auditoria::class)->registrar([
            'usuario' => $evento->user,
            'accion' => 'sesion.entrar',
        ]));

        Event::listen(Logout::class, fn (Logout $evento) => $evento->user && app(Auditoria::class)->registrar([
            'usuario' => $evento->user,
            'accion' => 'sesion.salir',
        ]));

        Event::listen(Failed::class, function (Failed $evento) {
            $correo = (string) ($evento->credentials['email'] ?? '');
            app(Auditoria::class)->registrar([
                'correo' => $correo,
                'codisb' => $evento->user instanceof SidesUsers ? $evento->user->codisb : null,
                'accion' => 'sesion.fallida',
                'resultado' => 'FALLIDO',
                'detalle_resultado' => $evento->user ? 'Contraseña incorrecta o usuario inactivo' : 'El correo no existe',
            ]);
        });
    }
}
