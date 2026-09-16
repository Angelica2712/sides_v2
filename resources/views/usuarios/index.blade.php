@php
    use App\Support\PermisosUsuario;

    $etiquetas = PermisosUsuario::etiquetas();
@endphp

<x-layouts.app titulo="Usuarios">
    <div class="mx-auto max-w-6xl space-y-4">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-xl font-extrabold text-slate-900">Usuarios</h2>
                <p class="text-sm text-slate-500">
                    {{ $usuarios->total() }} {{ $usuarios->total() === 1 ? 'usuario' : 'usuarios' }}{{ $buscar !== '' ? ' con la búsqueda aplicada' : ' de la sucursal' }}
                </p>
            </div>
            <a href="{{ route('usuarios.create') }}" class="rounded-xl bg-primary px-4 py-2.5 text-sm font-bold text-white hover:bg-primary-dark">Nuevo usuario</a>
        </div>

        <form method="GET" action="{{ route('usuarios.index') }}" role="search"
              class="flex flex-wrap items-end gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
            <div class="min-w-56 flex-1">
                <label for="buscar" class="block text-xs font-semibold text-slate-600">Buscar</label>
                <input id="buscar" name="buscar" type="search" value="{{ $buscar }}" placeholder="Nombre o correo"
                       class="mt-1 w-full rounded-xl border-slate-300 px-3.5 py-2 text-sm shadow-sm focus:border-primary focus:ring-primary">
            </div>
            <button type="submit" class="rounded-xl bg-slate-800 px-4 py-2 text-sm font-bold text-white hover:bg-slate-900">Buscar</button>
            @if ($buscar !== '')
                <a href="{{ route('usuarios.index') }}" class="rounded-xl px-3 py-2 text-sm font-semibold text-slate-500 hover:text-slate-800">Limpiar</a>
            @endif
        </form>

        @if ($usuarios->isEmpty())
            <section class="rounded-2xl bg-white p-12 text-center shadow-sm ring-1 ring-slate-200">
                <h3 class="text-lg font-bold text-slate-900">{{ $buscar !== '' ? 'Ningún usuario coincide con la búsqueda' : 'La sucursal no tiene usuarios' }}</h3>
            </section>
        @else
            <x-tabla-desplazable etiqueta="Lista de usuarios" class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-bold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th scope="col" class="px-4 py-3">Usuario</th>
                            <th scope="col" class="px-4 py-3">Estado</th>
                            <th scope="col" class="px-4 py-3">Permisos</th>
                            <th scope="col" class="px-4 py-3"><span class="sr-only">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($usuarios as $usuario)
                            @php
                                $permisos = collect($etiquetas)->filter(fn ($etiqueta, $columna) => $usuario->{$columna})->values();
                                $editable = ! $usuario->esAdmin || auth()->user()->esAdmin;
                            @endphp
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-3">
                                    <p class="font-semibold text-slate-800">
                                        {{ $usuario->name }}
                                        @if ($usuario->is(auth()->user()))
                                            <span class="text-xs font-semibold text-slate-500">(tú)</span>
                                        @endif
                                    </p>
                                    <p class="text-xs text-slate-500">{{ $usuario->email }}</p>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-bold ring-1 {{ $usuario->estado === 'ACTIVO' ? 'bg-emerald-50 text-emerald-800 ring-emerald-200' : 'bg-slate-100 text-slate-600 ring-slate-300' }}">
                                        {{ $usuario->estado === 'ACTIVO' ? 'Activo' : 'Inactivo' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex max-w-xl flex-wrap gap-1">
                                        @if ($usuario->esAdmin)
                                            <span class="rounded-md bg-primary-soft px-2 py-0.5 text-xs font-bold text-primary">Administrador</span>
                                        @endif
                                        @forelse ($permisos as $permiso)
                                            <span class="rounded-md bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-700">{{ $permiso }}</span>
                                        @empty
                                            <span class="text-xs text-slate-500">Sin permisos</span>
                                        @endforelse
                                    </div>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">
                                    @if ($editable)
                                        <a href="{{ route('usuarios.edit', $usuario->id) }}" class="font-semibold text-slate-500 hover:text-primary">Modificar</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-tabla-desplazable>

            @if ($usuarios->hasPages())
                <nav aria-label="Paginación" class="flex flex-wrap items-center justify-between gap-3 text-sm">
                    <p class="text-slate-500">Página {{ $usuarios->currentPage() }} de {{ $usuarios->lastPage() }}</p>
                    <div class="flex gap-2">
                        @foreach ([['Anterior', $usuarios->previousPageUrl()], ['Siguiente', $usuarios->nextPageUrl()]] as [$texto, $url])
                            @if ($url)
                                <a href="{{ $url }}" class="rounded-xl bg-white px-4 py-2 font-semibold text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">{{ $texto }}</a>
                            @else
                                <span class="rounded-xl px-4 py-2 font-semibold text-slate-300 ring-1 ring-slate-200">{{ $texto }}</span>
                            @endif
                        @endforeach
                    </div>
                </nav>
            @endif
        @endif
    </div>
</x-layouts.app>
