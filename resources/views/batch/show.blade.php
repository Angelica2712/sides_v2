@php
    $fecha = fn ($valor) => $valor ? \Illuminate\Support\Carbon::parse($valor)->format('d-m-y H:i') : '—';
    $numero = fn ($valor) => number_format((int) $valor, 0, ',', '.');
    $estados = [
        'ABIERTO' => ['Por iniciar', 'Falta asignarle un recipiente e iniciar el picking.', 'bg-slate-100 text-slate-700 ring-slate-200'],
        'CONFIRMADO' => ['En picking', 'El picking del lote está en curso.', 'bg-amber-50 text-amber-700 ring-amber-200'],
        'TERMINADO' => ['Terminado', 'Todos sus pedidos pasaron a la etapa siguiente.', 'bg-emerald-50 text-emerald-700 ring-emerald-200'],
        'ANULADO' => ['Anulado', 'Sus pedidos volvieron a quedar en espera.', 'bg-rose-50 text-rose-700 ring-rose-200'],
    ];
    [$estadoTexto, $estadoAyuda, $estadoClase] = $estados[$lote->estado] ?? [$lote->estado, '', 'bg-slate-100 text-slate-700 ring-slate-200'];
    $miNombre = auth()->user()->name;
@endphp

<x-layouts.app :titulo="'Lote #'.$lote->id">
    <div class="mx-auto max-w-5xl space-y-4" x-data>
        <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <a href="{{ route('batch.index') }}" class="text-sm font-semibold text-primary hover:underline">← Volver a Batch Picking</a>
            <div class="mt-1 flex flex-wrap items-center gap-2">
                <h2 class="text-2xl font-black text-slate-900">Lote #{{ $lote->id }}</h2>
                <span class="rounded-full px-2.5 py-0.5 text-xs font-bold ring-1 {{ $estadoClase }}">{{ $estadoTexto }}</span>
            </div>
            <p class="mt-1 text-sm text-slate-600">{{ $estadoAyuda }}</p>
            <p class="mt-1 text-xs text-slate-500">
                {{ $lote->origen_creacion === 'SUGERIDO' ? 'Sugerido automáticamente' : 'Armado a mano' }}
                @if ($lote->creado_por) por {{ $lote->creado_por }} @endif · {{ $fecha($lote->fecha_creacion) }}
                @if ($responsable) · picking de {{ $responsable }} @endif
            </p>
        </section>

        <section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-bold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th scope="col" class="px-4 py-2.5">Pedido</th>
                            <th scope="col" class="px-4 py-2.5">Cliente</th>
                            <th scope="col" class="px-4 py-2.5">Ruta</th>
                            <th scope="col" class="px-4 py-2.5">Estado</th>
                            <th scope="col" class="px-4 py-2.5 text-right">Renglones</th>
                            <th scope="col" class="px-4 py-2.5 text-right">Unidades</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-slate-700">
                        @foreach ($pedidos as $pedido)
                            <tr>
                                <td class="px-4 py-2 font-bold text-slate-900 tabular-nums">#{{ $pedido->id }}</td>
                                <td class="px-4 py-2">{{ $pedido->nomcli }} <span class="text-xs text-slate-400">{{ $pedido->codcli }}</span></td>
                                <td class="px-4 py-2">{{ $pedido->ruta ?: '—' }}</td>
                                <td class="px-4 py-2">{{ $pedido->estado }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $numero($pedido->numren) }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $numero($pedido->numund) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        @if ($lote->estado === 'ABIERTO')
            <section class="grid gap-3 sm:grid-cols-[1fr_auto]">
                <form method="POST" action="{{ route('batch.iniciar', $lote->id) }}" class="flex flex-wrap items-end gap-2 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                    @csrf
                    <div class="min-w-48 flex-1">
                        <label for="recipiente" class="block text-sm font-semibold text-slate-700">Recipiente para todo el lote</label>
                        <input id="recipiente" name="recipiente" type="text" required maxlength="50" placeholder="Escanea o escribe el código de la cesta"
                               class="mt-1.5 block w-full rounded-xl border-slate-300 px-3.5 py-2.5 text-lg font-bold shadow-sm focus:border-primary focus:ring-primary">
                    </div>
                    <button type="submit" class="rounded-xl bg-primary px-5 py-3 text-sm font-bold text-white hover:bg-primary-dark">Iniciar picking del lote</button>
                </form>
                <div class="flex items-end">
                    <button type="button" @click="$refs.anular.showModal()" class="w-full rounded-xl bg-white px-4 py-3 text-sm font-semibold text-rose-700 ring-1 ring-rose-200 hover:bg-rose-50">Anular lote</button>
                </div>
            </section>

            <dialog x-ref="anular" class="m-auto w-[min(26rem,calc(100%-2rem))] rounded-2xl p-0 shadow-xl backdrop:bg-slate-900/60">
                <form method="POST" action="{{ route('batch.anular', $lote->id) }}" class="space-y-4 p-6">
                    @csrf
                    <h3 class="text-lg font-extrabold text-slate-900">¿Anular el lote #{{ $lote->id }}?</h3>
                    <p class="text-sm text-slate-600">Sus {{ $pedidos->count() }} pedidos vuelven a quedar en espera para agruparlos de nuevo o liberarlos.</p>
                    <div class="flex justify-end gap-2">
                        <button type="button" @click="$refs.anular.close()" class="rounded-xl px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100">Cancelar</button>
                        <button type="submit" class="rounded-xl bg-rose-600 px-4 py-2 text-sm font-bold text-white hover:bg-rose-700">Sí, anular</button>
                    </div>
                </form>
            </dialog>
        @elseif ($lote->estado === 'CONFIRMADO')
            @if ($responsable === $miNombre)
                <a href="{{ route('batch.picking', $lote->id) }}" class="block rounded-2xl bg-primary px-5 py-3 text-center font-bold text-white hover:bg-primary-dark">Continuar picking del lote</a>
            @else
                <p class="rounded-2xl bg-slate-100 px-5 py-3 text-center text-sm font-semibold text-slate-600">Lo está trabajando {{ $responsable ?: 'otro operario' }}.</p>
            @endif
        @endif
    </div>
</x-layouts.app>
