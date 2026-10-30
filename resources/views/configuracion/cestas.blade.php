@php
    $campo = 'mt-1 block w-full rounded-xl border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-primary focus:ring-primary';
    $enUso = fn ($cesta) => $ocupadas->get(\App\Services\Picking\CestasService::normalizar($cesta->codigo));
@endphp

<x-layouts.app titulo="Cestas">
    <div class="mx-auto max-w-3xl space-y-4">
        <a href="{{ route('picking.index') }}" class="text-sm font-semibold text-primary-ink hover:underline">← Volver a picking</a>

        @if ($errors->any())
            <div role="alert" class="rounded-xl bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800 ring-1 ring-rose-200">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('cestas.store') }}" class="space-y-3 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
            @csrf
            <div>
                <h2 class="text-lg font-extrabold text-slate-900">Cestas de la droguería</h2>
                <p class="text-sm text-slate-500">En picking solo se puede usar una cesta de esta lista, y no se puede tomar la que esté ocupada con otro pedido.</p>
            </div>
            <div class="grid gap-3 sm:grid-cols-[1fr_6rem_6rem]">
                <div>
                    <label for="prefijo" class="block text-xs font-semibold text-slate-600">Agregar varias: nombre</label>
                    <input id="prefijo" name="prefijo" type="text" maxlength="40" placeholder="Ej.: CESTA" value="{{ old('prefijo') }}" class="{{ $campo }}">
                </div>
                <div>
                    <label for="desde" class="block text-xs font-semibold text-slate-600">Desde</label>
                    <input id="desde" name="desde" type="number" min="0" inputmode="numeric" value="{{ old('desde') }}" class="{{ $campo }}">
                </div>
                <div>
                    <label for="hasta" class="block text-xs font-semibold text-slate-600">Hasta</label>
                    <input id="hasta" name="hasta" type="number" min="0" inputmode="numeric" value="{{ old('hasta') }}" class="{{ $campo }}">
                </div>
            </div>
            <div>
                <label for="lista" class="block text-xs font-semibold text-slate-600">O escribe o escanea los códigos, uno por línea</label>
                <textarea id="lista" name="lista" rows="3" class="{{ $campo }}">{{ old('lista') }}</textarea>
            </div>
            <div class="flex justify-end">
                <button type="submit" class="rounded-xl bg-primary px-4 py-2 text-sm font-bold text-on-primary hover:bg-primary-dark">Agregar</button>
            </div>
        </form>

        <div class="space-y-2">
            @forelse ($cestas as $cesta)
                @php($ocupante = $enUso($cesta))
                <div class="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                    <div class="min-w-40 flex-1">
                        <p class="font-bold text-slate-900 {{ $cesta->activo ? '' : 'line-through opacity-60' }}">{{ $cesta->codigo }}</p>
                        @if ($ocupante)
                            <p class="text-sm font-semibold text-amber-700">
                                Ocupada: pedido #{{ $ocupante->id }}@if ($ocupante->despasignado && $ocupante->despachador) · {{ $ocupante->despachador }}@endif
                            </p>
                        @elseif ($cesta->activo)
                            <p class="text-sm text-emerald-700">Libre</p>
                        @else
                            <p class="text-sm text-slate-500">Desactivada</p>
                        @endif
                    </div>
                    <form method="POST" action="{{ route('cestas.update', $cesta->id) }}">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="activo" value="{{ $cesta->activo ? 0 : 1 }}">
                        <button type="submit" class="rounded-xl bg-white px-4 py-2 text-sm font-semibold text-slate-700 ring-1 ring-slate-200 hover:bg-slate-50">{{ $cesta->activo ? 'Desactivar' : 'Activar' }}</button>
                    </form>
                    @unless ($ocupante)
                        <form method="POST" action="{{ route('cestas.destroy', $cesta->id) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="rounded-xl bg-white px-4 py-2 text-sm font-semibold text-rose-700 ring-1 ring-rose-200 hover:bg-rose-50">Borrar</button>
                        </form>
                    @endunless
                </div>
            @empty
                <p class="rounded-2xl bg-white p-6 text-center text-sm text-slate-500 ring-1 ring-slate-200">Todavía no hay cestas registradas. Sin cestas no se puede tomar ningún pedido.</p>
            @endforelse
        </div>
    </div>
</x-layouts.app>
