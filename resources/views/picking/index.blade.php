@php
    $fecha = fn ($valor) => $valor ? \Illuminate\Support\Carbon::parse($valor)->format('d-m-y H:i') : '—';
    $numero = fn ($valor) => number_format((int) $valor, 0, ',', '.');
    $miNombre = auth()->user()->name;
@endphp

<x-layouts.app titulo="Picking">
    {{-- Recarga silenciosa cada 5 minutos, como el legacy. --}}
    <div class="mx-auto max-w-6xl space-y-4" x-data x-init="setTimeout(() => location.reload(), 300000)">

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-xl font-extrabold text-slate-900">Pedidos para picking</h2>
                <p class="text-sm text-slate-500">
                    {{ $pedidos->count() }} {{ $pedidos->count() === 1 ? 'pedido' : 'pedidos' }} recibidos o en picking, del más antiguo al más nuevo
                </p>
            </div>

            <form method="GET" action="{{ route('picking.index') }}" class="flex w-full gap-2 sm:w-auto" role="search">
                <label for="buscar" class="sr-only">Buscar pedido</label>
                <input id="buscar" name="buscar" type="search" value="{{ $buscar }}" placeholder="Pedido, recipiente, cliente o ruta"
                       class="w-full rounded-xl border-slate-300 bg-white px-3.5 py-2 text-sm shadow-sm focus:border-primary focus:ring-primary sm:w-72">
                <button type="submit" class="rounded-xl bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm ring-1 ring-slate-200 hover:bg-slate-50">Buscar</button>
                @if ($buscar !== '')
                    <a href="{{ route('picking.index') }}" class="rounded-xl px-3 py-2 text-sm font-semibold text-slate-500 hover:text-slate-800">Limpiar</a>
                @endif
            </form>
        </div>

        @if ($miPedido)
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-primary px-5 py-4 text-white shadow-sm">
                <p class="font-semibold">Tienes el pedido <span class="font-black">#{{ $miPedido }}</span> en picking.</p>
                <a href="{{ route('picking.show', $miPedido) }}" class="rounded-xl bg-white px-4 py-2 text-sm font-bold text-primary hover:bg-primary-soft">Continuar picking</a>
            </div>
        @endif

        @if ($lotes->isNotEmpty())
            <section aria-labelledby="titulo-lotes-batch" class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 id="titulo-lotes-batch" class="font-extrabold text-slate-900">Lotes de Batch Picking</h3>
                    <a href="{{ route('batch.index') }}" class="text-sm font-semibold text-primary hover:underline">Ir a Batch Picking</a>
                </div>
                <ul class="mt-3 grid gap-2 sm:grid-cols-2">
                    @foreach ($lotes as $lote)
                        <li class="flex items-center justify-between gap-3 rounded-xl bg-slate-50 px-3 py-2.5">
                            <div class="min-w-0">
                                <p class="font-bold text-slate-900">Lote #{{ $lote->id }} · {{ $lote->pedidos_count }} {{ $lote->pedidos_count === 1 ? 'pedido' : 'pedidos' }}</p>
                                <p class="truncate text-xs text-slate-500">
                                    {{ $lote->estado === 'ABIERTO' ? 'Por iniciar' : 'En picking' }}@if ($lote->responsable) · {{ $lote->responsable === $miNombre ? 'lo tienes tú' : $lote->responsable }}@endif
                                </p>
                            </div>
                            @if ($lote->estado === 'CONFIRMADO' && $lote->responsable === $miNombre)
                                <a href="{{ route('batch.picking', $lote->id) }}" class="shrink-0 rounded-lg bg-primary px-3 py-1.5 text-xs font-bold text-white hover:bg-primary-dark">Continuar</a>
                            @elseif ($lote->estado === 'ABIERTO')
                                <a href="{{ route('batch.show', $lote->id) }}" class="shrink-0 rounded-lg bg-white px-3 py-1.5 text-xs font-bold text-primary ring-1 ring-slate-300 hover:bg-slate-100">Ver lote</a>
                            @else
                                <span class="shrink-0 rounded-lg bg-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-500">En uso</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($pedidos->isEmpty())
            <section class="rounded-2xl bg-white p-12 text-center shadow-sm ring-1 ring-slate-200">
                <h3 class="text-lg font-bold text-slate-900">{{ $buscar !== '' ? 'No hay pedidos que coincidan con la búsqueda' : 'No hay pedidos para picking' }}</h3>
                <p class="mt-1 text-sm text-slate-500">Los pedidos recibidos de SEPED aparecerán aquí.</p>
            </section>
        @else
            <ul class="space-y-3">
                @foreach ($pedidos as $pedido)
                    @php
                        $tomado = $pedido->despasignado && $pedido->despachador;
                        $esMio = $tomado && $pedido->despachador === $miNombre;
                    @endphp
                    <li x-data class="flex flex-col gap-4 rounded-2xl bg-white p-4 shadow-sm ring-1 sm:flex-row sm:items-center sm:justify-between {{ $esMio ? 'ring-2 ring-primary' : 'ring-slate-200' }}">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-2xl font-black text-slate-900 tabular-nums">#{{ $pedido->id }}</span>
                                <span class="rounded-full px-2.5 py-0.5 text-xs font-bold ring-1 {{ $pedido->estado === 'PICKING' ? 'bg-amber-50 text-amber-700 ring-amber-200' : 'bg-slate-100 text-slate-700 ring-slate-200' }}">
                                    {{ $pedido->estado }}
                                </span>
                                @if ($pedido->recipiente)
                                    <span class="rounded-md bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-700">Recipiente {{ $pedido->recipiente }}</span>
                                @endif
                            </div>
                            <p class="mt-1 truncate text-sm font-semibold text-slate-700">
                                {{ $pedido->ruta ?: 'Sin ruta' }} · {{ $pedido->nomcli }}
                            </p>
                            <p class="text-xs text-slate-500">
                                {{ $pedido->codcli }} · procesado {{ $fecha($pedido->fecprocesado) }} ·
                                {{ $numero($pedido->numren) }} renglones · {{ $numero($pedido->numund) }} unidades
                                @if ($tomado)
                                    · <span class="font-semibold text-slate-700">{{ $esMio ? 'Lo tienes tú' : 'Tomado por '.$pedido->despachador }}</span>
                                @endif
                            </p>
                        </div>

                        <div class="flex shrink-0 flex-wrap gap-2">
                            @if ($esMio)
                                <a href="{{ route('picking.show', $pedido->id) }}" class="rounded-xl bg-primary px-4 py-2.5 text-sm font-bold text-white hover:bg-primary-dark">Continuar</a>
                                <button type="button" @click="$refs.liberar.showModal()" class="rounded-xl bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">Liberar</button>

                                <dialog x-ref="liberar" class="m-auto w-[min(26rem,calc(100%-2rem))] rounded-2xl p-0 shadow-xl backdrop:bg-slate-900/60">
                                    <form method="POST" action="{{ route('picking.liberar', $pedido->id) }}" class="space-y-4 p-6">
                                        @csrf
                                        <h3 class="text-lg font-extrabold text-slate-900">¿Liberar el pedido #{{ $pedido->id }}?</h3>
                                        <p class="text-sm text-slate-600">Queda disponible para otro operario. Lo que ya revisaste se conserva y se registra el tiempo parcial.</p>
                                        <div class="flex justify-end gap-2">
                                            <button type="button" @click="$refs.liberar.close()" class="rounded-xl px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100">Cancelar</button>
                                            <button type="submit" class="rounded-xl bg-primary px-4 py-2 text-sm font-bold text-white hover:bg-primary-dark">Liberar pedido</button>
                                        </div>
                                    </form>
                                </dialog>
                            @elseif ($tomado)
                                <span class="rounded-xl bg-slate-100 px-4 py-2.5 text-sm font-semibold text-slate-500">En uso</span>
                            @elseif ($miPedido)
                                <span class="rounded-xl bg-slate-100 px-4 py-2.5 text-sm font-semibold text-slate-500" title="Termina o libera tu pedido actual para tomar otro">Termina tu pedido actual</span>
                            @else
                                <button type="button" @click="$refs.tomar.showModal(); $nextTick(() => $refs.recipiente.focus())"
                                        class="rounded-xl bg-primary px-5 py-2.5 text-sm font-bold text-white hover:bg-primary-dark">Tomar pedido</button>

                                <dialog x-ref="tomar" class="m-auto w-[min(26rem,calc(100%-2rem))] rounded-2xl p-0 shadow-xl backdrop:bg-slate-900/60">
                                    <form method="POST" action="{{ route('picking.tomar', $pedido->id) }}" class="space-y-4 p-6">
                                        @csrf
                                        <div>
                                            <h3 class="text-lg font-extrabold text-slate-900">Tomar el pedido #{{ $pedido->id }}</h3>
                                            <p class="mt-1 text-sm text-slate-600">{{ $pedido->nomcli }}</p>
                                        </div>
                                        <div>
                                            <label for="recipiente-{{ $pedido->id }}" class="block text-sm font-semibold text-slate-700">Recipiente</label>
                                            <input x-ref="recipiente" id="recipiente-{{ $pedido->id }}" name="recipiente" type="text" maxlength="50"
                                                   value="{{ $pedido->recipiente }}" @if ($pedido->recipiente) readonly @else required @endif
                                                   placeholder="Escanea o escribe el código de la cesta"
                                                   class="mt-1.5 block w-full rounded-xl border-slate-300 px-3.5 py-2.5 text-lg font-bold shadow-sm focus:border-primary focus:ring-primary read-only:bg-slate-100">
                                        </div>
                                        <div class="flex justify-end gap-2">
                                            <button type="button" @click="$refs.tomar.close()" class="rounded-xl px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100">Cancelar</button>
                                            <button type="submit" class="rounded-xl bg-primary px-4 py-2 text-sm font-bold text-white hover:bg-primary-dark">Tomar y empezar</button>
                                        </div>
                                    </form>
                                </dialog>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</x-layouts.app>
