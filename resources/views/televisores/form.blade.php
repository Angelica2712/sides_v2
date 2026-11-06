@php
    $nuevo = $tv->estado !== 'ACTIVO';
    $campo = 'mt-1.5 block w-full rounded-xl border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-primary focus:ring-primary';
    // Tras un error se conserva lo que se marcó; al vincular, el Monitor viene marcado.
    $marcada = fn (string $columna, bool $porDefecto) => session()->hasOldInput()
        ? (bool) old($columna)
        : ($nuevo ? $porDefecto : (bool) $tv->{$columna});
    $vistas = [
        'vistaMonitor' => ['Monitor', 'Los pedidos en Recibidos, Picking y Packing con los minutos que llevan, y los conteos del día.', true],
        'vistaPicking' => ['Picking en vivo', 'Cada despachador con su pedido, su avance y el producto que busca, con el Almacén 3D si la droguería lo usa.', false],
        'vistaAlertas' => ['Alertas', 'Pedidos en retraso y el rendimiento de cada despachador contra su propio promedio. Muestra nombres de empleados.', false],
    ];
@endphp

<x-layouts.app :titulo="$nuevo ? 'Vincular televisor' : 'Modificar televisor'">
    <div class="mx-auto max-w-2xl space-y-4">
        <a href="{{ route('televisores.index') }}" class="text-sm font-semibold text-primary-ink hover:underline">← Volver a Televisores</a>

        <form method="POST" action="{{ $nuevo ? route('televisores.store') : route('televisores.update', $tv->id) }}" class="space-y-4">
            @csrf
            @unless ($nuevo) @method('PUT') @endunless
            @if ($nuevo)
                <input type="hidden" name="codigo" value="{{ $codigo }}">
            @endif

            <section class="space-y-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
                <div>
                    <h2 class="text-lg font-extrabold text-slate-900">{{ $nuevo ? 'Vincular el televisor '.$codigo : $tv->alias }}</h2>
                    <p class="text-sm text-slate-500">
                        {{ $tv->plataforma ?: 'App de TV' }}@if ($tv->app_version) · versión {{ $tv->app_version }}@endif
                        · identificador …{{ substr($tv->app_uuid, -6) }}.
                        @if ($nuevo) Confirma que es el mismo que se ve en la pantalla. @endif
                    </p>
                </div>

                <div>
                    <label for="alias" class="block text-sm font-semibold text-slate-700">Nombre del televisor</label>
                    <input id="alias" name="alias" type="text" maxlength="100" required placeholder="TV del almacén"
                           value="{{ old('alias', $tv->alias) }}" class="{{ $campo }}">
                    <p class="mt-1 text-xs text-slate-500">Para reconocerlo en esta lista. Lo ve también la app.</p>
                </div>

                <div>
                    <label for="usuario_id" class="block text-sm font-semibold text-slate-700">Usuario</label>
                    <select id="usuario_id" name="usuario_id" required class="{{ $campo }}">
                        <option value="">Elige un usuario…</option>
                        @foreach ($usuarios as $usuario)
                            <option value="{{ $usuario->id }}" @selected((int) old('usuario_id', $tv->usuario_id) === $usuario->id)>{{ $usuario->name }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-slate-500">
                        Usuarios activos con Monitor o con «Pantalla de TV». Si lo desactivas o lo eliminas, sus televisores dejan de mostrar SIDES.
                    </p>
                </div>
            </section>

            <section class="space-y-3 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
                <div>
                    <h3 class="font-extrabold text-slate-900">Qué muestra</h3>
                    <p class="text-sm text-slate-500">Una o varias. Si marcas varias, la app las va rotando.</p>
                </div>
                @foreach ($vistas as $columna => [$etiqueta, $ayuda, $porDefecto])
                    <label class="flex cursor-pointer items-start gap-3 rounded-xl p-3 ring-1 ring-slate-200 has-checked:bg-primary-soft has-checked:ring-primary">
                        <input type="checkbox" name="{{ $columna }}" value="1" @checked($marcada($columna, $porDefecto))
                               class="mt-0.5 size-5 rounded border-slate-300 text-primary-ink focus:ring-primary">
                        <span>
                            <span class="block font-bold text-slate-900">{{ $etiqueta }}</span>
                            <span class="block text-sm text-slate-500">{{ $ayuda }}</span>
                        </span>
                    </label>
                @endforeach
            </section>

            <div class="flex justify-end gap-2">
                <a href="{{ route('televisores.index') }}" class="rounded-xl px-4 py-2.5 text-sm font-semibold text-slate-600 hover:bg-slate-100">Cancelar</a>
                <button type="submit" class="rounded-xl bg-primary px-5 py-2.5 text-sm font-bold text-on-primary hover:bg-primary-dark">
                    {{ $nuevo ? 'Vincular televisor' : 'Guardar cambios' }}
                </button>
            </div>
        </form>

        @unless ($nuevo)
            <form method="POST" action="{{ route('televisores.destroy', $tv->id) }}"
                  onsubmit="return confirm(@js('¿Desvincular '.$tv->alias.'? Deja de mostrar SIDES y para volver a usarlo tiene que pedir un código nuevo.'))"
                  class="flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-rose-200">
                @csrf
                @method('DELETE')
                <p class="text-sm text-slate-600">Desvincularlo lo saca de SIDES al momento.</p>
                <button type="submit" class="rounded-xl px-4 py-2.5 text-sm font-bold text-rose-700 ring-1 ring-rose-300 hover:bg-rose-50">Desvincular</button>
            </form>
        @endunless
    </div>
</x-layouts.app>
