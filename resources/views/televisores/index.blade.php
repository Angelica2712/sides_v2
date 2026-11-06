@php
    $campo = 'mt-1.5 block w-full rounded-xl border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-primary focus:ring-primary';
    $nombresVista = ['monitor' => 'Monitor', 'alertas' => 'Alertas', 'picking' => 'Picking en vivo'];
@endphp

<x-layouts.app titulo="Televisores">
    <div class="mx-auto max-w-4xl space-y-4">
        <div>
            <a href="{{ route('configuracion.index') }}" class="text-sm font-semibold text-primary-ink hover:underline">← Volver a Configuración</a>
            <h2 class="mt-1 text-xl font-extrabold text-slate-900">Televisores</h2>
            <p class="text-sm text-slate-500">
                {{ $tvs->count() }} {{ $tvs->count() === 1 ? 'televisor vinculado' : 'televisores vinculados' }} a la app de TV de la droguería.
            </p>
        </div>

        {{-- Paso 1: el código que muestra la TV. El paso 2 (nombre, usuario y vistas) es televisores.create. --}}
        <section class="space-y-3 rounded-2xl bg-primary-soft p-5 ring-1 ring-primary/20">
            <div>
                <h3 class="font-extrabold text-slate-900">Vincular un televisor</h3>
                <p class="mt-1 text-sm text-slate-700">
                    Abre la app en el televisor: muestra un código de 6 caracteres que vale 10 minutos. Escríbelo aquí,
                    con o sin el guion. Después le pones un nombre y eliges qué muestra.
                </p>
            </div>
            <form method="GET" action="{{ route('televisores.create') }}" class="flex flex-wrap items-end gap-3">
                <div class="w-full max-w-xs">
                    <label for="codigo" class="block text-sm font-semibold text-slate-700">Código del televisor</label>
                    <input id="codigo" name="codigo" type="text" required maxlength="7" autocomplete="off" autocapitalize="characters"
                           placeholder="ABC-234" value="{{ old('codigo') }}"
                           class="{{ $campo }} font-mono text-lg font-bold uppercase tracking-widest">
                </div>
                <button type="submit" class="rounded-xl bg-primary px-5 py-2.5 text-sm font-bold text-on-primary hover:bg-primary-dark">Continuar</button>
            </form>
        </section>

        @if ($tvs->isEmpty())
            <section class="rounded-2xl bg-white p-12 text-center shadow-sm ring-1 ring-slate-200">
                <h3 class="text-lg font-bold text-slate-900">Todavía no hay televisores</h3>
                <p class="mt-1 text-sm text-slate-500">Vincula uno con el código que muestra la app.</p>
            </section>
        @else
            <x-tabla-desplazable etiqueta="Televisores vinculados" class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-bold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th scope="col" class="px-4 py-3">Nombre</th>
                            <th scope="col" class="px-4 py-3">Muestra</th>
                            <th scope="col" class="px-4 py-3">Usuario</th>
                            <th scope="col" class="px-4 py-3">Conexión</th>
                            <th scope="col" class="px-4 py-3"><span class="sr-only">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($tvs as $tv)
                            @php $conectada = $tv->last_seen_at && $tv->last_seen_at->gte($limiteConexion); @endphp
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-3">
                                    <span class="font-semibold text-slate-800">{{ $tv->alias }}</span>
                                    <span class="block text-xs text-slate-500">
                                        {{ $tv->plataforma ?: 'App de TV' }}@if ($tv->app_version) · v{{ $tv->app_version }}@endif · …{{ substr($tv->app_uuid, -6) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-slate-600">{{ collect($tv->vistas())->map(fn ($v) => $nombresVista[$v])->join(', ') }}</td>
                                <td class="px-4 py-3 text-slate-600">{{ $tv->usuario?->name ?? '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    <span @class([
                                        'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-bold ring-1',
                                        'bg-emerald-50 text-emerald-800 ring-emerald-200' => $conectada,
                                        'bg-slate-50 text-slate-600 ring-slate-300' => ! $conectada,
                                    ])>{{ $conectada ? 'Conectada' : 'Sin conexión' }}</span>
                                    <span class="block pt-1 text-xs text-slate-500">
                                        {{ $tv->last_seen_at ? 'Última vez '.$tv->last_seen_at->diffForHumans() : 'Todavía no ha entrado' }}
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">
                                    <a href="{{ route('televisores.edit', $tv->id) }}" class="font-semibold text-slate-500 hover:text-primary-ink">Modificar</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-tabla-desplazable>
        @endif

        <section class="space-y-3 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h3 class="font-extrabold text-slate-900">Accesos rechazados</h3>
                    <p class="text-sm text-slate-500">Los últimos 20 de los televisores de esta droguería: un televisor desvinculado o una vista que se le quitó.</p>
                </div>
                <form method="POST" action="{{ route('televisores.purgar') }}" class="flex items-end gap-2"
                      onsubmit="return confirm('¿Borrar los accesos rechazados más viejos?')">
                    @csrf
                    <label for="dias" class="sr-only">Antigüedad</label>
                    <select id="dias" name="dias" class="rounded-xl border-slate-300 py-2 text-sm">
                        @foreach ($diasPurga as $dias)
                            <option value="{{ $dias }}" @selected($dias === 7)>Más de {{ $dias }} {{ $dias === 1 ? 'día' : 'días' }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="rounded-xl px-4 py-2 text-sm font-bold text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">Borrar</button>
                </form>
            </div>
            @if ($fallos->isEmpty())
                <p class="text-sm text-slate-500">No hay accesos rechazados.</p>
            @else
                <ul class="divide-y divide-slate-100 text-sm">
                    @foreach ($fallos as $fallo)
                        <li class="flex flex-wrap justify-between gap-2 py-2">
                            <span class="font-semibold text-slate-800">{{ $fallo->alias ?: 'TV sin nombre' }}</span>
                            <span class="text-slate-600">
                                {{ (int) $fallo->status_code === 403 ? 'Pidió una vista que no tiene' : 'Rechazado ('.$fallo->status_code.')' }}
                                · {{ $fallo->ip }} · {{ \Illuminate\Support\Carbon::parse($fallo->created_at)->format('d/m/Y h:i a') }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
</x-layouts.app>
