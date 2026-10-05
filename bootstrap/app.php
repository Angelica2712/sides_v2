<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'permiso' => \App\Http\Middleware\EnsureSidesPermiso::class,
            'siad.token' => \App\Http\Middleware\VerifySiadApiToken::class,
            'siad.abierta' => \App\Http\Middleware\VerifySiadApiAbierta::class,
        ]);
        $middleware->web(append: [
            \App\Http\Middleware\CerrarSesionInactivo::class,
            \App\Http\Middleware\RegistrarAuditoria::class,
        ]);
        $middleware->redirectUsersTo('/home');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Formulario enviado desde una página que quedó abierta hasta vencer la sesión (login o
        // "Cerrar sesión"): en vez del error 419, de vuelta al login con el aviso.
        $exceptions->render(function (HttpException $e, Request $request) {
            if ($e->getStatusCode() !== 419 || $request->expectsJson()) {
                return null;
            }

            return redirect()->route('login')
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'La página estuvo abierta mucho tiempo y venció. Vuelve a intentarlo.']);
        });
    })->create();
