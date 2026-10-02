<?php

namespace App\Http\Controllers;

use App\Models\Sides\SidesUsers;
use App\Services\Auditoria\Auditoria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Paso del FT (FULLTECH360) entre SEPED y SIDES sin volver a escribir la contraseña.
 * Cada paso es un pase de un solo uso en sso_pases (tabla de SEPED en la base compartida):
 * se guarda solo el sha256 del token, vence a los 60 segundos y cada sistema acepta
 * únicamente los pases con su propio nombre en `destino`. El token nunca se registra.
 */
class SsoController extends Controller
{
    private const VIGENCIA_SEGUNDOS = 60;

    public function __construct(private readonly Auditoria $auditoria)
    {
    }

    /** GET /sso?token=…: el FT llega desde el botón "Ir a SIDES" de SEPED. */
    public function recibir(Request $request): RedirectResponse
    {
        // Mismo mensaje para todo (vencido, usado, inexistente, usuario inactivo): no dice qué falló.
        $error = redirect()->route('login')
            ->withErrors(['email' => 'El enlace para entrar desde SEPED venció o ya se usó. Vuelve a tocar el botón o entra con tu correo.']);

        $token = (string) $request->query('token', '');
        if ($token === '') {
            return $error;
        }

        $hash = hash('sha256', $token);

        // Validar y marcar usado en una sola sentencia: dos pestañas con el mismo enlace no entran las dos.
        $marcado = DB::table('sso_pases')
            ->where('token_hash', $hash)
            ->where('destino', 'SIDES')
            ->whereNull('usado_at')
            ->where('expira_at', '>', Carbon::now())
            ->update(['usado_at' => Carbon::now()]);

        if (! $marcado) {
            return $error;
        }

        $pase = DB::table('sso_pases')->where('token_hash', $hash)->first();
        $usuario = SidesUsers::query()
            ->where('seped_user_id', $pase->seped_user_id)
            ->where('estado', 'ACTIVO')
            ->where('esAdmin', 1)
            ->first();

        if (! $usuario) {
            return $error;
        }

        // Si había otra sesión abierta (de otro usuario), se cierra antes.
        if (Auth::check()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
        }

        Auth::login($usuario);
        $request->session()->regenerate();

        $this->auditoria->registrar(['usuario' => $usuario, 'accion' => 'sso.desde_seped'], $request);

        return redirect()->route('home');
    }

    /** GET /ir-a-seped: botón del menú, solo para el FT y si SEPED_URL está configurado. */
    public function irASeped(Request $request): RedirectResponse
    {
        $url = self::urlSeped();
        $usuario = $request->user();

        abort_if($url === null || ! $usuario->esFt(), 404);

        $token = Str::random(64);
        DB::table('sso_pases')->insert([
            'token_hash' => hash('sha256', $token),
            'seped_user_id' => $usuario->seped_user_id,
            'origen' => 'SIDES',
            'destino' => 'SEPED',
            'expira_at' => Carbon::now()->addSeconds(self::VIGENCIA_SEGUNDOS),
            'ip' => $request->ip(),
            'created_at' => Carbon::now(),
        ]);

        $this->auditoria->registrar(['usuario' => $usuario, 'accion' => 'sso.ir_a_seped'], $request);

        return redirect()->away("{$url}/sso?token={$token}");
    }

    /** URL de SEPED sin la barra final, o null si no está configurada. */
    public static function urlSeped(): ?string
    {
        $url = rtrim((string) config('services.seped.url'), '/');

        return $url === '' ? null : $url;
    }
}
