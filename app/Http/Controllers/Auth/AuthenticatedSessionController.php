<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\Sides\SidesCfg;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /** Cookie con la droguería del último usuario que entró en este equipo (para su logo en el login). */
    public const COOKIE_DROGUERIA = 'sides_drogueria';

    /**
     * El login muestra el logo de la droguería cuando se sabe cuál es: por su enlace de
     * entrada (/login/{codisb}, lo copia FULLTECH360 en Administración), por el equipo,
     * que recuerda la droguería del último que entró, o si solo hay una configurada.
     */
    public function create(Request $request, ?string $codisb = null): View
    {
        $cfg = $this->drogueria($codisb)
            ?? $this->drogueria($request->cookie(self::COOKIE_DROGUERIA))
            ?? (SidesCfg::query()->count() === 1 ? SidesCfg::query()->first() : null);

        return view('auth.login', ['cfg' => $cfg]);
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        // FULLTECH360 entra desde cualquier equipo: no cambia el logo que recuerda el de una droguería.
        $usuario = $request->user();
        if (! $usuario->esAdmin) {
            Cookie::queue(Cookie::forever(self::COOKIE_DROGUERIA, $usuario->codisb));
        }

        return redirect()->intended(route('home', absolute: false));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function drogueria(mixed $codisb): ?SidesCfg
    {
        return is_string($codisb) && $codisb !== '' ? SidesCfg::query()->find($codisb) : null;
    }
}
