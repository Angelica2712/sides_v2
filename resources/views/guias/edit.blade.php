@php
    use Illuminate\Support\Carbon;

    $campo = 'mt-1.5 block w-full rounded-xl border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-primary focus:ring-primary';
    $paraInput = fn ($fecha) => $fecha ? Carbon::parse($fecha)->format('Y-m-d\TH:i') : '';
    $chofer = (string) old('chofer', $guia->chofer);
    $auxiliar = (string) old('auxiliar', $guia->chofer_aux_id);
@endphp

<x-layouts.app :titulo="'Modificar guía #'.$guia->id">
    <div class="mx-auto max-w-2xl space-y-4">
        <a href="{{ route('guias.show', $guia->id) }}" class="text-sm font-semibold text-primary hover:underline">← Volver a la guía #{{ $guia->id }}</a>

        <form method="POST" action="{{ route('guias.update', $guia->id) }}" class="space-y-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200"
              x-data="{ chofer: @js($chofer) }">
            @csrf
            @method('PUT')
            <div>
                <h2 class="text-lg font-extrabold text-slate-900">Guía #{{ $guia->id }} · {{ $guia->ruta }}</h2>
                <p class="text-sm text-slate-500">Datos del viaje. La ruta no cambia; para pasar clientes a otro chofer usa "Separar guía".</p>
            </div>

            @if ($errors->any())
                <div role="alert" class="rounded-xl bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800 ring-1 ring-rose-200">
                    @foreach ($errors->all() as $error)
                        <p>{{ $error }}</p>
                    @endforeach
                </div>
            @endif

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="chofer" class="block text-sm font-semibold text-slate-700">Chofer</label>
                    <select id="chofer" name="chofer" required x-model="chofer" class="{{ $campo }}">
                        @foreach ($choferes as $opcion)
                            <option value="{{ $opcion->chof_co }}" @selected($chofer === (string) $opcion->chof_co)>{{ $opcion->chof_nom }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="auxiliar" class="block text-sm font-semibold text-slate-700">Auxiliar <span class="font-normal text-slate-400">(opcional)</span></label>
                    <select id="auxiliar" name="auxiliar" class="{{ $campo }}">
                        <option value="">Sin auxiliar</option>
                        @foreach ($choferes as $opcion)
                            <option value="{{ $opcion->chof_co }}" @selected($auxiliar === (string) $opcion->chof_co)
                                    x-bind:disabled="chofer === @js((string) $opcion->chof_co)">{{ $opcion->chof_nom }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="fecha" class="block text-sm font-semibold text-slate-700">Fecha de la guía</label>
                    <input id="fecha" name="fecha" type="datetime-local" required value="{{ old('fecha', $paraInput($guia->fecha)) }}" class="{{ $campo }}">
                </div>
                <div>
                    <label for="fecha_salida" class="block text-sm font-semibold text-slate-700">Salida del camión</label>
                    <input id="fecha_salida" name="fecha_salida" type="datetime-local" value="{{ old('fecha_salida', $paraInput($guia->fecha_salida)) }}" class="{{ $campo }}">
                </div>
                <div class="sm:col-span-2">
                    <label for="unidad" class="block text-sm font-semibold text-slate-700">Unidad</label>
                    <input id="unidad" name="unidad" type="text" maxlength="100" placeholder="Placa o nombre del vehículo" value="{{ old('unidad', $guia->unidad) }}" class="{{ $campo }}">
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <a href="{{ route('guias.show', $guia->id) }}" class="rounded-xl px-4 py-2.5 text-sm font-semibold text-slate-600 hover:bg-slate-100">Cancelar</a>
                <button type="submit" class="rounded-xl bg-primary px-5 py-2.5 text-sm font-bold text-white hover:bg-primary-dark">Guardar cambios</button>
            </div>
        </form>
    </div>
</x-layouts.app>
