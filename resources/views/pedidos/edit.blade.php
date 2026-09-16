@php
    use App\Support\FechaSeped;

    $campo = 'mt-1.5 block w-full rounded-xl border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-primary focus:ring-primary';
    $fechas = [
        'fecrecibido' => 'Recibido', 'fecpicking' => 'Picking', 'fecpacking' => 'Packing',
        'feccompletado' => 'Completado', 'fecfacturado' => 'Facturado',
    ];
@endphp

<x-layouts.app :titulo="'Modificar pedido #'.$pedido->id">
    <div class="mx-auto max-w-3xl space-y-4">
        <a href="{{ route('pedidos.show', $pedido->id) }}" class="text-sm font-semibold text-primary hover:underline">← Volver al pedido</a>

        <form method="POST" action="{{ route('pedidos.update', $pedido->id) }}" class="space-y-4">
            @csrf
            @method('PUT')

            @if ($errors->any())
                <div role="alert" class="rounded-xl bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800 ring-1 ring-rose-200">
                    @foreach ($errors->all() as $error)
                        <p>{{ $error }}</p>
                    @endforeach
                </div>
            @endif

            <section class="space-y-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
                <div>
                    <h2 class="text-lg font-extrabold text-slate-900">Modificar pedido #{{ $pedido->id }}</h2>
                    <p class="font-semibold text-slate-700">{{ $pedido->nomcli }}</p>
                    <p class="mt-1 text-sm text-slate-500">Es un cambio manual: para volver a trabajar el pedido desde cero usa <strong>Resetear</strong> en el detalle.</p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="estado" class="block text-sm font-semibold text-slate-700">Estado</label>
                        <select id="estado" name="estado" class="{{ $campo }}">
                            @foreach ($estados as $estado)
                                <option value="{{ $estado }}" @selected(old('estado', $pedido->estado) === $estado)>{{ $estado }}</option>
                            @endforeach
                            @unless (in_array($pedido->estado, $estados, true))
                                <option value="{{ $pedido->estado }}" disabled @selected(! old('estado'))>{{ $pedido->estado }} (actual)</option>
                            @endunless
                        </select>
                    </div>
                    <div>
                        <label for="recipiente" class="block text-sm font-semibold text-slate-700">Recipiente</label>
                        <input id="recipiente" name="recipiente" type="text" maxlength="50" value="{{ old('recipiente', $pedido->recipiente_sides) }}" class="{{ $campo }}">
                    </div>

                    @foreach ($fechas as $nombre => $etiqueta)
                        <div>
                            <label for="{{ $nombre }}" class="block text-sm font-semibold text-slate-700">{{ $etiqueta }}</label>
                            <input id="{{ $nombre }}" name="{{ $nombre }}" type="datetime-local" value="{{ old($nombre, FechaSeped::paraInput($pedido->{$nombre})) }}" class="{{ $campo }}">
                        </div>
                    @endforeach

                    <div class="sm:col-span-2">
                        <label for="observacion" class="block text-sm font-semibold text-slate-700">Observación</label>
                        <textarea id="observacion" name="observacion" rows="3" maxlength="500" class="{{ $campo }}">{{ old('observacion', $pedido->observacion) }}</textarea>
                    </div>
                </div>
            </section>

            <div class="flex justify-end gap-2">
                <a href="{{ route('pedidos.show', $pedido->id) }}" class="rounded-xl px-4 py-2.5 text-sm font-semibold text-slate-600 hover:bg-slate-100">Cancelar</a>
                <button type="submit" class="rounded-xl bg-primary px-5 py-2.5 text-sm font-bold text-white hover:bg-primary-dark">Guardar cambios</button>
            </div>
        </form>
    </div>
</x-layouts.app>
