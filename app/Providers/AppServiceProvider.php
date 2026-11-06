<?php

namespace App\Providers;

use App\Models\Sides\SidesUsers;
use App\Services\Auditoria\Auditoria;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
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
