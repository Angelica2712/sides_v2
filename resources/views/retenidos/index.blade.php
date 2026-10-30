@php
    use Illuminate\Support\Carbon;

    $fecha = fn ($valor) => $valor ? Carbon::parse($valor)->format('d-m-y H:i') : '—';
@endphp

<x-layouts.app titulo="Alcabala de packing">
    <div class="mx-auto max-w-5xl space-y-4">
        <div>
            <h2 class="text-xl font-extrabold text-slate-900">Alcabala de packing</h2>
            <p class="text-sm text-slate-500">
                Pedidos que ya terminaron el packing y esperan a otro del mismo cliente. Para anexar, termina el packing del pedido nuevo con «Anexar a los retenidos».
            </p>
        </div>

        @forelse ($retenciones as $retencion)
            <form method="POST" action="{{ route('retenidos.liberar', $retencion->id) }}" class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 sm:p-5">
                @csrf
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h3 class="text-lg font-extrabold text-slate-900 [overflow-wrap:anywhere]">{{ $retencion->nomcli }}</h3>
                        <p class="text-sm text-slate-500">{{ $retencion->codcli }} · retenido desde {{ $fecha($retencion->fecha) }}</p>
                    </div>
                    <button type="submit" class="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-bold text-white hover:bg-emerald-700">
                        {{ $retencion->pedidos->count() === 1 ? 'Enviar a facturar' : 'Enviar todos a facturar' }}
                    </button>
                </div>

                @if ($retencion->enCamino->isNotEmpty())
                    <p class="mt-3 rounded-xl bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-900 ring-1 ring-amber-200">
                        Vienen en camino de este cliente:
                        {{ $retencion->enCamino->map(fn ($p) => "#{$p->id} ({$p->estado})")->implode(', ') }}
                    </p>
                @endif

                <div class="mt-3 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-xs font-semibold uppercase text-slate-500">
                            <tr>
                                <th class="py-2 pr-3">Pedido</th>
                                <th class="py-2 pr-3">Ruta</th>
                                <th class="py-2 pr-3">Renglones</th>
                                <th class="py-2 pr-3">Unidades</th>
                                <th class="py-2 pr-3">Empacó</th>
                                <th class="py-2 pr-3">Retenido</th>
                                <th class="py-2 pr-3">Bultos</th>
                                <th class="py-2"><span class="sr-only">Acciones</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($retencion->pedidos as $pedido)
                                <tr>
                                    <td class="py-2 pr-3 font-bold tabular-nums">#{{ $pedido->id }}</td>
                                    <td class="py-2 pr-3">{{ $pedido->ruta ?: '—' }}</td>
                                    <td class="py-2 pr-3 tabular-nums">{{ $pedido->numren }}</td>
                                    <td class="py-2 pr-3 tabular-nums">{{ $pedido->numund }}</td>
                                    <td class="py-2 pr-3">{{ $pedido->embalador ?: '—' }}</td>
                                    <td class="py-2 pr-3 whitespace-nowrap">{{ $fecha($pedido->retenido_desde) }}</td>
                                    <td class="py-2 pr-3">
                                        <label for="bultos-{{ $pedido->id }}" class="sr-only">Bultos del pedido {{ $pedido->id }}</label>
                                        <input id="bultos-{{ $pedido->id }}" name="bultos[{{ $pedido->id }}]" type="number" min="1" max="999" inputmode="numeric"
                                               value="{{ (int) $pedido->cantBultos ?: 1 }}"
                                               class="w-20 rounded-lg border-slate-300 px-2 py-1 text-sm shadow-sm focus:border-primary focus:ring-primary">
                                    </td>
                                    <td class="py-2 text-right">
                                        @if ($retencion->pedidos->count() > 1)
                                            <button type="submit" name="pedido" value="{{ $pedido->id }}" class="whitespace-nowrap rounded-lg px-2 py-1 text-xs font-semibold text-primary-ink hover:bg-slate-100">Enviar solo este</button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </form>
        @empty
            <div class="rounded-2xl bg-white p-8 text-center shadow-sm ring-1 ring-slate-200">
                <p class="font-semibold text-slate-700">No hay pedidos retenidos.</p>
                <p class="mt-1 text-sm text-slate-500">Se retienen desde Packing, al terminar un pedido.</p>
            </div>
        @endforelse
    </div>
</x-layouts.app>
