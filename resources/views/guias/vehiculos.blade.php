@php
    $campo = 'mt-1 block w-full rounded-xl border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-primary focus:ring-primary';
@endphp

<x-layouts.app titulo="Carros">
    <div class="mx-auto max-w-3xl space-y-4">
        <a href="{{ route('guias.index') }}" class="text-sm font-semibold text-primary-ink hover:underline">← Volver a guías</a>

        @if ($errors->any())
            <div role="alert" class="rounded-xl bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800 ring-1 ring-rose-200">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('vehiculos.store') }}" class="grid gap-3 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 sm:grid-cols-[10rem_1fr_auto] sm:items-end">
            @csrf
            <div class="sm:col-span-3">
                <h2 class="text-lg font-extrabold text-slate-900">Carros de la droguería</h2>
                <p class="text-sm text-slate-500">Los que se pueden asignar a una guía de despacho.</p>
            </div>
            <div>
                <label for="placa" class="block text-xs font-semibold text-slate-600">Placa</label>
                <input id="placa" name="placa" type="text" required maxlength="20" value="{{ old('placa') }}" class="{{ $campo }}">
            </div>
            <div>
                <label for="descripcion" class="block text-xs font-semibold text-slate-600">Descripción</label>
                <input id="descripcion" name="descripcion" type="text" maxlength="70" placeholder="Ej.: Camión NPR blanco" value="{{ old('descripcion') }}" class="{{ $campo }}">
            </div>
            <button type="submit" class="rounded-xl bg-primary px-4 py-2 text-sm font-bold text-on-primary hover:bg-primary-dark">Agregar</button>
        </form>

        <div class="space-y-2">
            @forelse ($vehiculos as $vehiculo)
                <form method="POST" action="{{ route('vehiculos.update', $vehiculo->id) }}" class="grid gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 sm:grid-cols-[10rem_1fr_auto_auto] sm:items-center">
                    @csrf
                    @method('PUT')
                    <div>
                        <label for="placa-{{ $vehiculo->id }}" class="sr-only">Placa</label>
                        <input id="placa-{{ $vehiculo->id }}" name="placa" type="text" required maxlength="20" value="{{ $vehiculo->placa }}" class="{{ $campo }} mt-0 font-bold">
                    </div>
                    <div>
                        <label for="descripcion-{{ $vehiculo->id }}" class="sr-only">Descripción</label>
                        <input id="descripcion-{{ $vehiculo->id }}" name="descripcion" type="text" maxlength="70" value="{{ $vehiculo->descripcion }}" class="{{ $campo }} mt-0">
                    </div>
                    <label class="flex items-center gap-2 text-sm font-semibold text-slate-700">
                        <input type="checkbox" name="activo" value="1" @checked($vehiculo->activo) class="rounded border-slate-300 text-primary focus:ring-primary">
                        Activo
                    </label>
                    <button type="submit" class="rounded-xl bg-white px-4 py-2 text-sm font-semibold text-slate-700 ring-1 ring-slate-200 hover:bg-slate-50">Guardar</button>
                </form>
            @empty
                <p class="rounded-2xl bg-white p-6 text-center text-sm text-slate-500 ring-1 ring-slate-200">Todavía no hay carros registrados.</p>
            @endforelse
        </div>
    </div>
</x-layouts.app>
