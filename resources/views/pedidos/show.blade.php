@php
    use App\Support\FechaSeped;

    $numero = fn ($valor) => number_format((int) $valor, 0, ',', '.');
    $solicitado = $renglones->sum('cantidad');
    $despachado = $renglones->sum(fn ($renglon) => max((int) $renglon->cantdesp, 0));

    $datos = [
        'Código cliente' => $pedido->codcli,
        'RIF' => $pedido->rif,
        'Ruta' => $pedido->ruta,
        'Días de crédito' => $pedido->dcredito,
        'Vendedor' => $pedido->codvend,
        'Usuario' => $pedido->usuario,
        'Origen' => $pedido->origen,
        'Tipo' => $pedido->tipedido,
        'Documento en el ERP' => $pedido->documento,
        'Transporte' => $pedido->codtransp,
        'Factor cambiario' => filled($pedido->factorcambiario) ? number_format((float) $pedido->factorcambiario, 2, ',', '.') : null,
        'Teléfono' => $pedido->telefono,
        'Recipiente' => $pedido->recipiente_sides,
        'Despachador' => $pedido->despachador_sides,
        'Embalador' => $pedido->embalador_sides,
        'Bultos' => $pedido->bultos_sides,
        'Cestas' => $pedido->cestas_sides,
    ];
    $fechas = [
        'Creado' => $pedido->fecha, 'Enviado' => $pedido->fecenviado, 'Procesado' => $pedido->fecprocesado,
        'Recibido' => $pedido->fecrecibido, 'Picking' => $pedido->fecpicking, 'Packing' => $pedido->fecpacking,
        'Completado' => $pedido->feccompletado, 'Facturado' => $pedido->fecfacturado,
    ];
    $estadoRenglon = fn (?string $estado) => match ($estado) {
        'FACTURADO' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'PARCIAL' => 'bg-amber-50 text-amber-800 ring-amber-200',
        'NO FACTURADO' => 'bg-rose-50 text-rose-700 ring-rose-200',
        default => 'bg-slate-100 text-slate-600 ring-slate-200',
    };
@endphp

<x-layouts.app :titulo="'Pedido #'.$pedido->id">
    <div class="mx-auto max-w-6xl space-y-4" x-data>
        <a href="{{ route('pedidos.index') }}" class="text-sm font-semibold text-primary hover:underline">← Volver a pedidos</a>

        <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="text-2xl font-black text-slate-900">Pedido #{{ $pedido->id }}</h2>
                        <x-estado-pedido :estado="$pedido->estado" />
                        @if ($lote)
                            <span class="rounded-full bg-sky-50 px-2.5 py-0.5 text-xs font-bold text-sky-700 ring-1 ring-sky-200">Lote #{{ $lote->id }} · {{ $lote->estado }}</span>
                        @endif
                    </div>
                    <p class="mt-1 truncate text-lg font-semibold text-slate-700">{{ $pedido->nomcli }}</p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('pedidos.edit', $pedido->id) }}" class="rounded-xl bg-white px-4 py-2 text-sm font-semibold text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">Modificar</a>
                    @if ($puedeResetear)
                        <button type="button" @click="$refs.resetear.showModal()" class="rounded-xl bg-white px-4 py-2 text-sm font-semibold text-amber-700 ring-1 ring-amber-300 hover:bg-amber-50">Resetear</button>
                    @endif
                    @if ($puedeAnular && $pedido->estado !== 'ANULADO')
                        <button type="button" @click="$refs.anular.showModal()" class="rounded-xl bg-white px-4 py-2 text-sm font-semibold text-rose-700 ring-1 ring-rose-300 hover:bg-rose-50">Anular pedido</button>
                    @endif
                </div>
            </div>

            <dl class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
                @foreach (['Renglones' => $renglones->count(), 'Solicitado' => $solicitado, 'Despachado' => $despachado, 'Faltante' => max($solicitado - $despachado, 0)] as $etiqueta => $valor)
                    <div class="rounded-xl bg-slate-50 px-4 py-3">
                        <dt class="text-xs font-semibold text-slate-500">{{ $etiqueta }}</dt>
                        <dd class="text-2xl font-black tabular-nums text-slate-900">{{ $numero($valor) }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>

        <div class="grid gap-4 lg:grid-cols-3">
            <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 lg:col-span-2">
                <h3 class="font-extrabold text-slate-900">Datos del pedido</h3>
                <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-3 text-sm sm:grid-cols-3">
                    @foreach ($datos as $etiqueta => $valor)
                        <div class="min-w-0">
                            <dt class="text-xs font-semibold text-slate-500">{{ $etiqueta }}</dt>
                            <dd class="truncate font-semibold text-slate-800" title="{{ $valor }}">{{ filled($valor) ? $valor : '—' }}</dd>
                        </div>
                    @endforeach
                    @foreach (['Dirección de entrega' => $pedido->entrega, 'Destino' => $pedido->destino, 'Observación' => $pedido->observacion] as $etiqueta => $valor)
                        @if (filled($valor))
                            <div class="col-span-2 sm:col-span-3">
                                <dt class="text-xs font-semibold text-slate-500">{{ $etiqueta }}</dt>
                                <dd class="text-slate-800">{{ $valor }}</dd>
                            </div>
                        @endif
                    @endforeach
                </dl>
            </section>

            <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
                <h3 class="font-extrabold text-slate-900">Fechas</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    @foreach ($fechas as $etiqueta => $valor)
                        <div class="flex justify-between gap-3">
                            <dt class="text-slate-500">{{ $etiqueta }}</dt>
                            <dd class="font-semibold tabular-nums text-slate-800">{{ FechaSeped::mostrar($valor) }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>
        </div>

        <section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
            <h3 class="px-5 pt-5 font-extrabold text-slate-900">Productos</h3>
            <x-tabla-desplazable etiqueta="Productos del pedido" class="mt-3">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-bold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th scope="col" class="px-4 py-3">Producto</th>
                            <th scope="col" class="px-4 py-3">Lote</th>
                            <th scope="col" class="px-4 py-3">Ubicación</th>
                            <th scope="col" class="px-4 py-3 text-right">Solicitado</th>
                            <th scope="col" class="px-4 py-3 text-right">Despachado</th>
                            <th scope="col" class="px-4 py-3">Despachador</th>
                            <th scope="col" class="px-4 py-3">Estado</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($renglones as $renglon)
                            <tr>
                                <td class="px-4 py-3">
                                    <p class="font-semibold text-slate-800">{{ $renglon->desprod }}</p>
                                    <p class="text-xs text-slate-500">{{ $renglon->codprod }} · <span class="font-mono">{{ $renglon->barra }}</span></p>
                                </td>
                                @php $vence = \App\Services\Despacho\RenglonesPedido::limpiarFecha($renglon->feclote); @endphp
                                <td class="whitespace-nowrap px-4 py-3 text-slate-700">
                                    {{ $renglon->lote ?: '—' }}
                                    @if (filled($vence) && strtoupper($vence) !== 'N/A')
                                        <span class="block text-xs text-slate-500">vence {{ $vence }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-slate-700">{{ $renglon->ubicacion ?: '—' }}</td>
                                <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ $numero($renglon->cantidad) }}</td>
                                <td class="px-4 py-3 text-right font-black tabular-nums">{{ $numero(max((int) $renglon->cantdesp, 0)) }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-700">{{ $renglon->despachador ?: '—' }}</td>
                                <td class="px-4 py-3">
                                    @if (filled($renglon->estado_desp))
                                        <span class="whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-bold ring-1 {{ $estadoRenglon($renglon->estado_desp) }}">{{ $renglon->estado_desp }}</span>
                                    @else
                                        <span class="text-slate-400">—</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-8 text-center text-slate-500">El pedido no tiene renglones.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-tabla-desplazable>
        </section>

        @if ($puedeResetear)
            <dialog x-ref="resetear" class="m-auto w-[min(28rem,calc(100%-2rem))] rounded-2xl p-0 shadow-xl backdrop:bg-slate-900/60">
                <form method="POST" action="{{ route('pedidos.resetear', $pedido->id) }}" class="space-y-4 p-6">
                    @csrf
                    <h3 class="text-lg font-extrabold text-slate-900">¿Resetear el pedido #{{ $pedido->id }}?</h3>
                    <p class="text-sm text-slate-600">El pedido vuelve a <strong>RECIBIDO</strong> para trabajarlo desde cero: se borra lo recogido y verificado, los tiempos, los responsables, el recipiente y las etiquetas sin cargar.</p>
                    <div class="flex justify-end gap-2">
                        <button type="button" @click="$refs.resetear.close()" class="rounded-xl px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100">Cancelar</button>
                        <button type="submit" class="rounded-xl bg-amber-600 px-4 py-2 text-sm font-bold text-white hover:bg-amber-700">Resetear pedido</button>
                    </div>
                </form>
            </dialog>
        @endif

        @if ($puedeAnular && $pedido->estado !== 'ANULADO')
            <dialog x-ref="anular" class="m-auto w-[min(28rem,calc(100%-2rem))] rounded-2xl p-0 shadow-xl backdrop:bg-slate-900/60">
                <form method="POST" action="{{ route('pedidos.anular', $pedido->id) }}" class="space-y-4 p-6">
                    @csrf
                    <h3 class="text-lg font-extrabold text-slate-900">¿Anular el pedido #{{ $pedido->id }}?</h3>
                    <p class="text-sm text-slate-600">El pedido queda <strong>ANULADO</strong> también en SEPED y deja de aparecer en Picking y Packing. No se borra: sigue en el historial.</p>
                    <div class="flex justify-end gap-2">
                        <button type="button" @click="$refs.anular.close()" class="rounded-xl px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100">Cancelar</button>
                        <button type="submit" class="rounded-xl bg-rose-600 px-4 py-2 text-sm font-bold text-white hover:bg-rose-700">Anular pedido</button>
                    </div>
                </form>
            </dialog>
        @endif
    </div>
</x-layouts.app>
