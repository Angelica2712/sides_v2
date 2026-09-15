@php
    $fecha = fn ($valor) => $valor ? \Illuminate\Support\Carbon::parse($valor)->format('d-m-y H:i') : '—';
    $numero = fn ($valor) => number_format((int) $valor, 0, ',', '.');
    $miNombre = auth()->user()->name;
    $idsEnEspera = $pedidos->pluck('id')->map(fn ($id) => (int) $id)->all();
@endphp

<x-layouts.app titulo="Batch Picking">
    <div class="mx-auto max-w-6xl space-y-5" x-data="{ seleccion: [], todos: @js($idsEnEspera) }">

        <div>
            <h2 class="text-xl font-extrabold text-slate-900">Batch Picking</h2>
            <p class="text-sm text-slate-500">
                Agrupa pedidos en un lote para recogerlos en un solo recorrido del almacén. Puedes agrupar los pedidos recibidos que nadie tomó todavía y los que llegaron en espera.
            </p>
        </div>

        {{-- Lotes en curso --}}
        @if ($lotes->isNotEmpty())
            <section aria-labelledby="titulo-lotes">
                <h3 id="titulo-lotes" class="mb-2 text-sm font-bold uppercase tracking-wide text-slate-500">Lotes en curso</h3>
                <ul class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($lotes as $lote)
                        <li class="flex flex-col gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <p class="text-lg font-black text-slate-900">Lote #{{ $lote->id }}</p>
                                    <p class="text-xs text-slate-500">
                                        {{ $lote->pedidos_count }} {{ $lote->pedidos_count === 1 ? 'pedido' : 'pedidos' }}
                                        · {{ $lote->origen_creacion === 'SUGERIDO' ? 'sugerido' : 'armado a mano' }}
                                        · {{ $fecha($lote->fecha_creacion) }}
                                    </p>
                                </div>
                                <span class="rounded-full px-2.5 py-0.5 text-xs font-bold ring-1 {{ $lote->estado === 'ABIERTO' ? 'bg-slate-100 text-slate-700 ring-slate-200' : 'bg-amber-50 text-amber-700 ring-amber-200' }}">
                                    {{ $lote->estado === 'ABIERTO' ? 'Por iniciar' : 'En picking' }}
                                </span>
                            </div>
                            @if ($lote->responsable)
                                <p class="text-xs text-slate-600">Picking de <span class="font-semibold">{{ $lote->responsable === $miNombre ? 'ti' : $lote->responsable }}</span> · recipiente {{ $lote->recipiente }}</p>
                            @endif
                            <div class="mt-auto flex gap-2">
                                @if ($lote->estado === 'CONFIRMADO' && $lote->responsable === $miNombre)
                                    <a href="{{ route('batch.picking', $lote->id) }}" class="flex-1 rounded-xl bg-primary px-3 py-2 text-center text-sm font-bold text-white hover:bg-primary-dark">Continuar picking</a>
                                @endif
                                <a href="{{ route('batch.show', $lote->id) }}" class="flex-1 rounded-xl bg-white px-3 py-2 text-center text-sm font-semibold text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">Ver lote</a>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        {{-- Pedidos en espera --}}
        <form method="POST" action="{{ route('batch.agrupar') }}" class="space-y-3">
            @csrf

            <div class="flex flex-wrap items-end justify-between gap-2">
                <h3 class="text-sm font-bold uppercase tracking-wide text-slate-500">
                    Pedidos para agrupar ({{ $pedidos->count() }})
                </h3>
                @if ($pedidos->isNotEmpty())
                    <button type="button" @click="seleccion = seleccion.length === todos.length ? [] : [...todos]"
                            class="text-sm font-semibold text-primary hover:underline"
                            x-text="seleccion.length === todos.length ? 'Quitar selección' : 'Seleccionar todos'">Seleccionar todos</button>
                @endif
            </div>

            @if ($pedidos->isEmpty())
                <section class="rounded-2xl bg-white p-10 text-center shadow-sm ring-1 ring-slate-200">
                    <h4 class="text-lg font-bold text-slate-900">No hay pedidos para agrupar</h4>
                    <p class="mt-1 text-sm text-slate-500">Aquí aparecen los pedidos recibidos que ningún operario tomó y los que llegaron en espera.</p>
                </section>
            @else
                <ul class="space-y-2">
                    @foreach ($pedidos as $pedido)
                        <li class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 transition"
                            :class="seleccion.includes({{ (int) $pedido->id }}) ? 'ring-2 ring-primary' : 'ring-slate-200'">
                            <label class="flex cursor-pointer items-start gap-3 p-4">
                                <input type="checkbox" name="pedidos[]" value="{{ $pedido->id }}" x-model.number="seleccion"
                                       class="mt-1 size-5 rounded border-slate-300 text-primary focus:ring-primary">
                                <span class="min-w-0 flex-1">
                                    <span class="flex flex-wrap items-center gap-2">
                                        <span class="text-lg font-black text-slate-900 tabular-nums">#{{ $pedido->id }}</span>
                                        <span class="rounded-full px-2 py-0.5 text-[11px] font-bold ring-1 {{ $pedido->estado === 'ALCABALA' ? 'bg-amber-50 text-amber-700 ring-amber-200' : 'bg-slate-100 text-slate-600 ring-slate-200' }}">
                                            {{ $pedido->estado === 'ALCABALA' ? 'En espera' : 'Recibido' }}
                                        </span>
                                        <span class="truncate text-sm font-semibold text-slate-700">{{ $pedido->nomcli }}</span>
                                    </span>
                                    <span class="block text-xs text-slate-500">
                                        {{ $pedido->codcli }} · {{ $pedido->ruta ?: 'Sin ruta' }} · {{ $fecha($pedido->fecha) }}
                                        · {{ $numero($pedido->numren) }} renglones · {{ $numero($pedido->numund) }} unidades
                                    </span>
                                </span>
                            </label>
                            <details class="border-t border-slate-100 px-4">
                                <summary class="cursor-pointer py-2 text-xs font-semibold text-primary">Ver productos</summary>
                                <div class="overflow-x-auto pb-3">
                                    <table class="min-w-full text-left text-xs">
                                        <thead class="text-slate-500">
                                            <tr><th class="py-1 pr-3">Código</th><th class="py-1 pr-3">Producto</th><th class="py-1 pr-3">Ubicación</th><th class="py-1 text-right">Cantidad</th></tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-100 text-slate-700">
                                            @foreach ($renglones[$pedido->id] ?? [] as $renglon)
                                                <tr>
                                                    <td class="py-1 pr-3">{{ $renglon->codprod }}</td>
                                                    <td class="py-1 pr-3">{{ $renglon->desprod }}</td>
                                                    <td class="py-1 pr-3">{{ $renglon->ubicacion }}</td>
                                                    <td class="py-1 text-right tabular-nums">{{ $numero($renglon->cantidad) }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </details>
                        </li>
                    @endforeach
                </ul>

                <div class="sticky bottom-4 z-20 flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-slate-900 px-4 py-3 text-white shadow-lg">
                    <span class="text-sm font-semibold"><span x-text="seleccion.length">0</span> seleccionados</span>
                    <div class="flex flex-wrap gap-2">
                        @if ($puedeLiberar)
                            <button type="button" :disabled="seleccion.length === 0" @click="$refs.liberar.showModal()"
                                    class="rounded-xl bg-white/10 px-4 py-2 text-sm font-bold text-white ring-1 ring-white/30 hover:bg-white/20 disabled:opacity-40">
                                Liberar al picking normal
                            </button>
                        @endif
                        <button type="submit" :disabled="seleccion.length === 0"
                                class="rounded-xl bg-primary px-4 py-2 text-sm font-bold text-white hover:bg-primary-dark disabled:opacity-40">
                            Agrupar en un lote
                        </button>
                    </div>
                </div>

                @if ($puedeLiberar)
                    <dialog x-ref="liberar" class="m-auto w-[min(26rem,calc(100%-2rem))] rounded-2xl p-0 shadow-xl backdrop:bg-slate-900/60">
                        <div class="space-y-4 p-6">
                            <h4 class="text-lg font-extrabold text-slate-900">¿Liberar <span x-text="seleccion.length"></span> pedidos?</h4>
                            <p class="text-sm text-slate-600">Los pedidos en espera pasan al picking normal como cualquier pedido recibido. Los que ya están recibidos no cambian.</p>
                            <div class="flex justify-end gap-2">
                                <button type="button" @click="$refs.liberar.close()" class="rounded-xl px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100">Cancelar</button>
                                <button type="submit" formaction="{{ route('batch.liberar') }}" class="rounded-xl bg-primary px-4 py-2 text-sm font-bold text-white hover:bg-primary-dark">Sí, liberar</button>
                            </div>
                        </div>
                    </dialog>
                @endif
            @endif
        </form>
    </div>
</x-layouts.app>
