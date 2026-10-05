@php
    $nuevo = ! $filtro->exists;
    $campo = 'mt-1.5 block w-full rounded-xl border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-primary focus:ring-primary';
@endphp

<x-layouts.app :titulo="$nuevo ? 'Nuevo filtro' : 'Modificar filtro'">
    <div class="mx-auto max-w-2xl space-y-4">
        <a href="{{ route('filtromonitor.index') }}" class="text-sm font-semibold text-primary-ink hover:underline">← Volver a filtro monitor</a>

        <form method="POST" action="{{ $nuevo ? route('filtromonitor.store') : route('filtromonitor.update', $filtro->id) }}" class="space-y-4">
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
                <h2 class="text-lg font-extrabold text-slate-900">{{ $nuevo ? 'Nuevo filtro' : $filtro->descrip }}</h2>

                <div>
                    <label for="descrip" class="block text-sm font-semibold text-slate-700">Descripción</label>
                    <input id="descrip" name="descrip" type="text" maxlength="100" required value="{{ old('descrip', $filtro->descrip) }}" class="{{ $campo }}">
                    <p class="mt-1 text-xs text-slate-500">El nombre de la pestaña en el Monitor.</p>
                </div>

                <div>
                    <label for="criterio" class="block text-sm font-semibold text-slate-700">Criterio</label>
                    <input id="criterio" name="criterio" type="text" maxlength="100" required placeholder="NORTE, SUR, CENTRO" value="{{ old('criterio', $filtro->criterio) }}" class="{{ $campo }}">
                    <p class="mt-1 text-xs text-slate-500">Fragmentos del nombre de la ruta, separados por coma. Un pedido entra al filtro si su ruta contiene alguno.</p>
                </div>

                <div>
                    <label for="caracterLogo" class="block text-sm font-semibold text-slate-700">Marca <span class="font-normal text-slate-400">(opcional)</span></label>
                    <input id="caracterLogo" name="caracterLogo" type="text" maxlength="10" value="{{ old('caracterLogo', $filtro->caracterLogo) }}" class="{{ $campo }}">
                    <p class="mt-1 text-xs text-slate-500">Texto corto que marca los pedidos que caen en este filtro.</p>
                </div>
            </section>

            <div class="flex justify-end gap-2">
                <a href="{{ route('filtromonitor.index') }}" class="rounded-xl px-4 py-2.5 text-sm font-semibold text-slate-600 hover:bg-slate-100">Cancelar</a>
                <button type="submit" class="rounded-xl bg-primary px-5 py-2.5 text-sm font-bold text-on-primary hover:bg-primary-dark">{{ $nuevo ? 'Crear filtro' : 'Guardar cambios' }}</button>
            </div>
        </form>

        @unless ($nuevo)
            <form method="POST" action="{{ route('filtromonitor.destroy', $filtro->id) }}"
                  onsubmit="return confirm(@js('¿Eliminar el filtro \"'.$filtro->descrip.'\"? No se puede deshacer.'))"
                  class="flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-rose-200">
                @csrf
                @method('DELETE')
                <div>
                    <h3 class="font-extrabold text-slate-900">Eliminar filtro</h3>
                    <p class="text-sm text-slate-500">Deja de aparecer como pestaña en el Monitor.</p>
                </div>
                <button type="submit" class="rounded-xl px-4 py-2.5 text-sm font-bold text-rose-700 ring-1 ring-rose-300 hover:bg-rose-50">Eliminar</button>
            </form>
        @endunless
    </div>
</x-layouts.app>
