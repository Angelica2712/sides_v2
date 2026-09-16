@php
    use App\Support\PermisosUsuario;

    $nuevo = ! $usuario->exists;
    $esYo = $usuario->is(auth()->user());
    $campo = 'mt-1.5 block w-full rounded-xl border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-primary focus:ring-primary';
    // old() de los permisos solo vale si el error vino de este formulario (no del de contraseña).
    $conOld = session()->hasOldInput('name');
    $marcado = fn (string $permiso) => $conOld ? (bool) old("permisos.{$permiso}") : (bool) $usuario->{$permiso};
    $claveError = $errors->getBag('clave');
@endphp

<x-layouts.app :titulo="$nuevo ? 'Nuevo usuario' : 'Modificar usuario'">
    <div class="mx-auto max-w-3xl space-y-4">
        <a href="{{ route('usuarios.index') }}" class="text-sm font-semibold text-primary hover:underline">← Volver a usuarios</a>

        <form method="POST" action="{{ $nuevo ? route('usuarios.store') : route('usuarios.update', $usuario->id) }}" class="space-y-4">
            @csrf
            @unless ($nuevo) @method('PUT') @endunless

            @if ($errors->any())
                <div role="alert" class="rounded-xl bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800 ring-1 ring-rose-200">
                    @foreach ($errors->all() as $error)
                        <p>{{ $error }}</p>
                    @endforeach
                </div>
            @endif

            <section class="space-y-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
                <h2 class="text-lg font-extrabold text-slate-900">{{ $nuevo ? 'Nuevo usuario' : $usuario->name }}</h2>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="name" class="block text-sm font-semibold text-slate-700">Nombre</label>
                        <input id="name" name="name" type="text" maxlength="255" required value="{{ old('name', $usuario->name) }}" class="{{ $campo }}">
                    </div>
                    <div>
                        <label for="email" class="block text-sm font-semibold text-slate-700">Correo</label>
                        <input id="email" name="email" type="email" maxlength="255" required autocomplete="off" value="{{ old('email', $usuario->email) }}" class="{{ $campo }}">
                        <p class="mt-1 text-xs text-slate-500">Con este correo entra a SIDES.</p>
                    </div>

                    @if ($nuevo)
                        <div>
                            <label for="password" class="block text-sm font-semibold text-slate-700">Contraseña</label>
                            <input id="password" name="password" type="password" minlength="6" required autocomplete="new-password" class="{{ $campo }}">
                            <p class="mt-1 text-xs text-slate-500">Al menos 6 caracteres.</p>
                        </div>
                        <div>
                            <label for="password_confirmation" class="block text-sm font-semibold text-slate-700">Repite la contraseña</label>
                            <input id="password_confirmation" name="password_confirmation" type="password" minlength="6" required autocomplete="new-password" class="{{ $campo }}">
                        </div>
                    @endif

                    <fieldset class="sm:col-span-2">
                        <legend class="text-sm font-semibold text-slate-700">Estado</legend>
                        <div class="mt-1.5 flex flex-wrap gap-2">
                            @foreach (['ACTIVO' => ['Activo', 'Puede entrar a SIDES.'], 'INACTIVO' => ['Inactivo', 'No puede entrar; si tiene la sesión abierta, sale.']] as $estado => [$titulo, $ayuda])
                                <label class="flex flex-1 cursor-pointer items-start gap-3 rounded-xl p-3 ring-1 ring-slate-200 has-checked:bg-primary-soft has-checked:ring-primary">
                                    <input type="radio" name="estado" value="{{ $estado }}" @checked(old('estado', $usuario->estado) === $estado)
                                           @disabled($esYo && $estado === 'INACTIVO')
                                           class="mt-0.5 size-5 border-slate-300 text-primary focus:ring-primary">
                                    <span>
                                        <span class="block font-bold text-slate-900">{{ $titulo }}</span>
                                        <span class="block text-sm text-slate-500">{{ $esYo && $estado === 'INACTIVO' ? 'No puedes desactivarte a ti mismo.' : $ayuda }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                </div>
            </section>

            <section class="space-y-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
                <div>
                    <h3 class="text-lg font-extrabold text-slate-900">Permisos</h3>
                    <p class="text-sm text-slate-500">Los módulos opcionales (Batch Picking, Etiquetas, Guías, Rutas) además tienen que estar activos para la sucursal.</p>
                </div>

                @foreach (PermisosUsuario::GRUPOS as $grupo => $permisos)
                    <fieldset class="space-y-2">
                        <legend class="text-xs font-bold uppercase tracking-wide text-slate-500">{{ $grupo }}</legend>
                        <div class="grid gap-2 sm:grid-cols-2">
                            @foreach ($permisos as $permiso => [$titulo, $ayuda])
                                @php $bloqueado = $esYo && $permiso === 'activarUsuario'; @endphp
                                <label class="flex cursor-pointer items-start gap-3 rounded-xl p-3 ring-1 ring-slate-200 has-checked:bg-primary-soft has-checked:ring-primary">
                                    @if ($bloqueado)
                                        {{-- Un checkbox deshabilitado no se envía: el valor viaja oculto. --}}
                                        <input type="hidden" name="permisos[{{ $permiso }}]" value="1">
                                    @endif
                                    <input type="checkbox" name="permisos[{{ $permiso }}]" value="1"
                                           @checked($bloqueado || $marcado($permiso)) @disabled($bloqueado)
                                           class="mt-0.5 size-5 rounded border-slate-300 text-primary focus:ring-primary">
                                    <span>
                                        <span class="block font-bold text-slate-900">{{ $titulo }}</span>
                                        <span class="block text-sm text-slate-500">{{ $bloqueado ? 'No puedes quitarte este permiso a ti mismo.' : $ayuda }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                @endforeach
            </section>

            <div class="flex justify-end gap-2">
                <a href="{{ route('usuarios.index') }}" class="rounded-xl px-4 py-2.5 text-sm font-semibold text-slate-600 hover:bg-slate-100">Cancelar</a>
                <button type="submit" class="rounded-xl bg-primary px-5 py-2.5 text-sm font-bold text-white hover:bg-primary-dark">{{ $nuevo ? 'Crear usuario' : 'Guardar cambios' }}</button>
            </div>
        </form>

        @unless ($nuevo)
            <form method="POST" action="{{ route('usuarios.clave', $usuario->id) }}" id="cambiar-clave"
                  class="space-y-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
                @csrf
                @method('PUT')
                <div>
                    <h3 class="text-lg font-extrabold text-slate-900">Cambiar contraseña</h3>
                    <p class="text-sm text-slate-500">El usuario entra con la nueva contraseña desde ahora.</p>
                </div>

                @if ($claveError->any())
                    <div role="alert" class="rounded-xl bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800 ring-1 ring-rose-200">
                        @foreach ($claveError->all() as $error)
                            <p>{{ $error }}</p>
                        @endforeach
                    </div>
                @endif

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="nueva_clave" class="block text-sm font-semibold text-slate-700">Nueva contraseña</label>
                        <input id="nueva_clave" name="password" type="password" minlength="6" required autocomplete="new-password" class="{{ $campo }}">
                    </div>
                    <div>
                        <label for="nueva_clave_confirmacion" class="block text-sm font-semibold text-slate-700">Repite la contraseña</label>
                        <input id="nueva_clave_confirmacion" name="password_confirmation" type="password" minlength="6" required autocomplete="new-password" class="{{ $campo }}">
                    </div>
                </div>
                <div class="flex justify-end">
                    <button type="submit" class="rounded-xl bg-slate-800 px-5 py-2.5 text-sm font-bold text-white hover:bg-slate-900">Cambiar contraseña</button>
                </div>
            </form>

            @unless ($esYo)
                <form method="POST" action="{{ route('usuarios.destroy', $usuario->id) }}"
                      onsubmit="return confirm(@js('¿Eliminar a '.$usuario->name.'? No se puede deshacer.'))"
                      class="flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-rose-200">
                    @csrf
                    @method('DELETE')
                    <div>
                        <h3 class="font-extrabold text-slate-900">Eliminar usuario</h3>
                        <p class="text-sm text-slate-500">Si solo quieres que deje de entrar, márcalo como Inactivo.</p>
                    </div>
                    <button type="submit" class="rounded-xl px-4 py-2.5 text-sm font-bold text-rose-700 ring-1 ring-rose-300 hover:bg-rose-50">Eliminar</button>
                </form>
            @endunless
        @endunless
    </div>
</x-layouts.app>
