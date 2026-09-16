<?php

namespace App\Http\Controllers;

use App\Models\Sides\SidesUsers;
use App\Support\PermisosUsuario;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Usuarios de la sucursal (legacy AdminusuarioController).
 *
 * Cambios frente al legacy: solo se ven y editan los usuarios de la propia sucursal (el legacy
 * listaba todos); la contraseña ya no se guarda en claro en sides_users.clave; quien no es
 * administrador no puede tocar al administrador; nadie puede quitarse a sí mismo el acceso.
 */
class UsuariosController extends Controller
{
    public function index(Request $request): View
    {
        $buscar = trim((string) $request->query('buscar', ''));

        $usuarios = SidesUsers::query()
            ->where('codisb', $request->user()->codisb)
            ->when($buscar !== '', fn ($consulta) => $consulta->where(fn ($q) => $q
                ->where('name', 'like', "%{$buscar}%")
                ->orWhere('email', 'like', "%{$buscar}%")))
            ->orderBy('estado')
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        return view('usuarios.index', ['usuarios' => $usuarios, 'buscar' => $buscar]);
    }

    public function create(): View
    {
        return view('usuarios.form', ['usuario' => new SidesUsers(['estado' => 'ACTIVO'])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->normalizarCorreo($request);
        $datos = $request->validate([
            ...$this->reglas(),
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('sides_users', 'email')],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ], $this->mensajes());

        $usuario = new SidesUsers();
        $usuario->forceFill([
            ...$this->datos($request, $datos),
            'password' => $datos['password'],
            'clave' => '',
            'codisb' => $request->user()->codisb,
        ])->save();

        return redirect()->route('usuarios.index')->with('mensaje', "Usuario {$usuario->name} creado.");
    }

    public function edit(Request $request, int $usuario): View
    {
        return view('usuarios.form', ['usuario' => $this->buscar($request, $usuario)]);
    }

    public function update(Request $request, int $usuario): RedirectResponse
    {
        $registro = $this->buscar($request, $usuario);
        $this->normalizarCorreo($request);
        $datos = $request->validate([
            ...$this->reglas(),
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('sides_users', 'email')->ignore($registro->id)],
        ], $this->mensajes());
        $datos = $this->datos($request, $datos);

        if ($registro->is($request->user()) && ($datos['estado'] !== 'ACTIVO' || ! $datos['activarUsuario'])) {
            throw ValidationException::withMessages([
                'estado' => 'No puedes desactivarte ni quitarte el permiso de Usuarios: quedarías sin acceso a esta pantalla.',
            ]);
        }

        $registro->forceFill($datos)->save();

        return redirect()->route('usuarios.index')->with('mensaje', "Usuario {$registro->name} actualizado.");
    }

    public function clave(Request $request, int $usuario): RedirectResponse
    {
        $registro = $this->buscar($request, $usuario);
        $datos = $request->validateWithBag('clave', [
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ], $this->mensajes());

        // Al cambiar la contraseña se invalida "recordarme" en los otros equipos del usuario.
        $registro->forceFill(['password' => $datos['password'], 'clave' => '', 'remember_token' => null])->save();

        return redirect()->route('usuarios.edit', $registro->id)->with('mensaje', "Contraseña de {$registro->name} cambiada.");
    }

    public function destroy(Request $request, int $usuario): RedirectResponse
    {
        $registro = $this->buscar($request, $usuario);

        if ($registro->is($request->user())) {
            return back()->with('error', 'No puedes eliminar tu propio usuario.');
        }

        $registro->delete();

        return redirect()->route('usuarios.index')->with('mensaje', "Usuario {$registro->name} eliminado.");
    }

    private function buscar(Request $request, int $id): SidesUsers
    {
        $usuario = SidesUsers::query()->where('codisb', $request->user()->codisb)->findOrFail($id);

        // Si un operario con permiso de Usuarios pudiera cambiarle la contraseña al administrador, entraría como él.
        abort_if($usuario->esAdmin && ! $request->user()->esAdmin, 403, 'Solo el administrador de SIDES puede modificar a otro administrador.');

        return $usuario;
    }

    private function reglas(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'estado' => ['required', Rule::in(['ACTIVO', 'INACTIVO'])],
        ];
    }

    private function mensajes(): array
    {
        return [
            'name.required' => 'Escribe el nombre del usuario.',
            'email.required' => 'Escribe el correo del usuario.',
            'email.email' => 'Escribe un correo válido.',
            'email.unique' => 'Ya existe un usuario con ese correo.',
            'estado.*' => 'Elige si el usuario está activo o inactivo.',
            'password.required' => 'Escribe la contraseña.',
            'password.confirmed' => 'Las dos contraseñas no coinciden.',
            'password.min' => 'La contraseña debe tener al menos 6 caracteres.',
        ];
    }

    /** El correo se guarda en minúsculas para que la validación de único no dependa de mayúsculas. */
    private function normalizarCorreo(Request $request): void
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
    }

    /** @return array<string, mixed> */
    private function datos(Request $request, array $validados): array
    {
        $datos = [
            'name' => trim($validados['name']),
            'email' => $validados['email'],
            'estado' => $validados['estado'],
        ];
        foreach (PermisosUsuario::columnas() as $permiso) {
            $datos[$permiso] = $request->boolean("permisos.{$permiso}") ? 1 : 0;
        }

        return $datos;
    }
}
