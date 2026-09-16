@php
    $campo = 'mt-1.5 block w-full rounded-xl border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-primary focus:ring-primary';
@endphp

<x-layouts.app titulo="Nueva guía">
    <div class="mx-auto max-w-2xl space-y-4">
        <a href="{{ route('guias.index') }}" class="text-sm font-semibold text-primary hover:underline">← Volver a guías</a>

        <form method="POST" action="{{ route('guias.store') }}" class="space-y-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
            @csrf
            <div>
                <h2 class="text-lg font-extrabold text-slate-900">Nueva guía de despacho</h2>
                <p class="text-sm text-slate-500">
                    Toma los pedidos facturados con etiquetas impresas de los clientes de la ruta (menos los que retiran en local), en el orden de la ruta.
                    Hay {{ $pendientes }} {{ $pendientes === 1 ? 'pedido pendiente' : 'pedidos pendientes' }} de despacho en la sucursal.
                </p>
            </div>

            @if ($errors->any())
                <div role="alert" class="rounded-xl bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800 ring-1 ring-rose-200">
                    @foreach ($errors->all() as $error)
                        <p>{{ $error }}</p>
                    @endforeach
                </div>
            @endif

            @if ($choferes->isEmpty() || $rutas->isEmpty())
                <p class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-amber-200">
                    @if ($choferes->isEmpty())
                        La sucursal no tiene choferes: se cargan en SEPED.
                    @endif
                    @if ($rutas->isEmpty())
                        La sucursal no tiene rutas. <a href="{{ route('rutas.index') }}" class="font-semibold underline">Crea una en Rutas</a>.
                    @endif
                </p>
            @endif

            <div>
                <label for="ruta" class="block text-sm font-semibold text-slate-700">Ruta</label>
                <select id="ruta" name="ruta" required class="{{ $campo }}">
                    <option value="">Elige la ruta</option>
                    @foreach ($rutas as $ruta)
                        <option value="{{ $ruta->id }}" @selected((string) old('ruta') === (string) $ruta->id)>{{ $ruta->nombre }}</option>
                    @endforeach
                </select>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="chofer" class="block text-sm font-semibold text-slate-700">Chofer</label>
                    <select id="chofer" name="chofer" required class="{{ $campo }}">
                        <option value="">Elige el chofer</option>
                        @foreach ($choferes as $chofer)
                            <option value="{{ $chofer->chof_co }}" @selected(old('chofer') === $chofer->chof_co)>{{ $chofer->chof_nom }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="fecha" class="block text-sm font-semibold text-slate-700">Fecha</label>
                    <input id="fecha" name="fecha" type="datetime-local" required value="{{ old('fecha', now()->format('Y-m-d\TH:i')) }}" class="{{ $campo }}">
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <a href="{{ route('guias.index') }}" class="rounded-xl px-4 py-2.5 text-sm font-semibold text-slate-600 hover:bg-slate-100">Cancelar</a>
                <button type="submit" class="rounded-xl bg-primary px-5 py-2.5 text-sm font-bold text-white hover:bg-primary-dark">Crear guía</button>
            </div>
        </form>
    </div>
</x-layouts.app>
