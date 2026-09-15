@php
    $fecha = fn ($valor) => $valor ? \Illuminate\Support\Carbon::parse($valor)->format('d-m-y H:i') : '—';
    $numero = fn ($valor) => number_format((int) $valor, 0, ',', '.');
    $miNombre = auth()->user()->name;
@endphp

<x-layouts.app titulo="Packing">
    {{-- Recarga silenciosa cada minuto, como el legacy. --}}
    <div class="mx-auto max-w-6xl space-y-4" x-data x-init="setTimeout(() => location.reload(), 60000)">

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-xl font-extrabold text-slate-900">Pedidos para packing</h2>
                <p class="text-sm text-slate-500">
                    {{ $pedidos->count() }} {{ $pedidos->count() === 1 ? 'pedido' : 'pedidos' }} con picking terminado, del más antiguo al más nuevo
                </p>
            </div>

            <form method="GET" action="{{ route('packing.index') }}" class="flex w-full gap-2 sm:w-auto" role="search">
                <label for="buscar" class="sr-only">Buscar pedido</label>
                <input id="buscar" name="buscar" type="search" value="{{ $buscar }}" placeholder="Pedido, recipiente o cliente"
                       class="w-full rounded-xl border-slate-300 bg-white px-3.5 py-2 text-sm shadow-sm focus:border-primary focus:ring-primary sm:w-72">
                <button type="submit" class="rounded-xl bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm ring-1 ring-slate-200 hover:bg-slate-50">Buscar</button>
                @if ($buscar !== '')
                    <a href="{{ route('packing.index') }}" class="rounded-xl px-3 py-2 text-sm font-semibold text-slate-500 hover:text-slate-800">Limpiar</a>
                @endif
            </form>
        </div>

        @if ($pedidos->isEmpty())
            <section class="rounded-2xl bg-white p-12 text-center shadow-sm ring-1 ring-slate-200">
                <h3 class="text-lg font-bold text-slate-900">{{ $buscar !== '' ? 'No hay pedidos que coincidan con la búsqueda' : 'No hay pedidos para packing' }}</h3>
                <p class="mt-1 text-sm text-slate-500">Cuando un operario termine un picking, el pedido aparecerá aquí.</p>
            </section>
        @else
            <ul class="space-y-3">
                @foreach ($pedidos as $pedido)
                    @php
                        $esMio = $pedido->embalador === $miNombre;
                        $ocupado = $pedido->embalador && ! $esMio;
                    @endphp
                    <li x-data class="flex flex-col gap-4 rounded-2xl bg-white p-4 shadow-sm ring-1 sm:flex-row sm:items-center sm:justify-between {{ $esMio ? 'ring-2 ring-primary' : 'ring-slate-200' }}">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-2xl font-black text-slate-900 tabular-nums">#{{ $pedido->id }}</span>
                                @if ($pedido->recipiente)
                                    <span class="rounded-md bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-700">Recipiente {{ $pedido->recipiente }}</span>
                                @endif
                            </div>
                            <p class="mt-1 truncate text-sm font-semibold text-slate-700">{{ $pedido->ruta ?: 'Sin ruta' }} · {{ $pedido->nomcli }}</p>
                            <p class="text-xs text-slate-500">
                                En packing desde {{ $fecha($pedido->fecpacking) }} · {{ $numero($pedido->numren) }} renglones · {{ $numero($pedido->numund) }} unidades
                                @if ($pedido->despachador) · picking de {{ $pedido->despachador }} @endif
                                @if ($pedido->embalador)
                                    · <span class="font-semibold text-slate-700">{{ $esMio ? 'Lo estás empacando tú' : 'Lo empaca '.$pedido->embalador }}</span>
                                @endif
                            </p>
                        </div>

                        <div class="flex shrink-0 flex-wrap gap-2">
                            @if ($esMio)
                                <a href="{{ route('packing.show', $pedido->id) }}" class="rounded-xl bg-primary px-4 py-2.5 text-sm font-bold text-white hover:bg-primary-dark">Continuar</a>
                                <button type="button" @click="$refs.liberar.showModal()" class="rounded-xl bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">Liberar</button>

                                <dialog x-ref="liberar" class="m-auto w-[min(26rem,calc(100%-2rem))] rounded-2xl p-0 shadow-xl backdrop:bg-slate-900/60">
                                    <form method="POST" action="{{ route('packing.liberar', $pedido->id) }}" class="space-y-4 p-6">
                                        @csrf
                                        <h3 class="text-lg font-extrabold text-slate-900">¿Liberar el pedido #{{ $pedido->id }}?</h3>
                                        <p class="text-sm text-slate-600">Queda disponible para otro empacador. Lo que ya verificaste se conserva.</p>
                                        <div class="flex justify-end gap-2">
                                            <button type="button" @click="$refs.liberar.close()" class="rounded-xl px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100">Cancelar</button>
                                            <button type="submit" class="rounded-xl bg-primary px-4 py-2 text-sm font-bold text-white hover:bg-primary-dark">Liberar pedido</button>
                                        </div>
                                    </form>
                                </dialog>
                            @elseif ($ocupado)
                                <span class="rounded-xl bg-slate-100 px-4 py-2.5 text-sm font-semibold text-slate-500">En uso</span>
                            @else
                                <a href="{{ route('packing.show', $pedido->id) }}" class="rounded-xl bg-primary px-5 py-2.5 text-sm font-bold text-white hover:bg-primary-dark">Empacar</a>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</x-layouts.app>
