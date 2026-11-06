@php
    use App\Services\Informes\OperacionesService;

    $t = fn (?int $s) => OperacionesService::texto($s);
    $numero = fn ($valor) => number_format((int) $valor, 0, ',', '.');
    $fechas = ['desde' => $desde->format('Y-m-d'), 'hasta' => $hasta->format('Y-m-d')];
    $porRuta = $agrupar === 'ruta';
    $servicio = $totales['pedidas'] > 0 ? round(100 * $totales['despachadas'] / $totales['pedidas'], 1) : null;
    $colorServicio = fn (?float $s) => match (true) {
        $s === null => 'text-slate-500',
        $s >= 98 => 'text-emerald-700',
        $s >= 90 => 'text-amber-700',
        default => 'text-rose-700',
    };
@endphp

<x-layouts.app titulo="Clientes y rutas">
    <div class="mx-auto max-w-6xl space-y-4">
        @include('operaciones.partials.encabezado', [
            'actual' => 'clientes',
            'titulo' => 'Clientes y rutas',
            'detalle' => 'Pedidos con el despacho ya cerrado: cuánto se le despachó a cada '.($porRuta ? 'ruta' : 'cliente').', qué tan completo y cuánto tardó su pedido de punta a punta.',
            'accion' => route('operaciones.clientes'),
            'extra' => '<input type="hidden" name="agrupar" value="'.e($agrupar).'">',
            'excel' => route('operaciones.clientes.excel', [...$fechas, 'agrupar' => $agrupar]),
        ])

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex gap-1 rounded-xl bg-slate-100 p-1" role="group" aria-label="Agrupar">
                @foreach (OperacionesService::AGRUPAR as $clave => $nombre)
                    <a href="{{ route('operaciones.clientes', [...$fechas, 'agrupar' => $clave]) }}" @if ($agrupar === $clave) aria-current="true" @endif
                       class="rounded-lg px-3 py-1.5 text-sm font-bold {{ $agrupar === $clave ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900' }}">{{ $nombre }}</a>
                @endforeach
            </div>
            <p class="text-sm text-slate-500">Pedidos llegados del {{ $desde->format('d-m-Y') }} al {{ $hasta->format('d-m-Y') }}.</p>
        </div>

        @if ($totales['pedidos'] === 0)
            <section class="rounded-2xl bg-white p-12 text-center shadow-sm ring-1 ring-slate-200">
                <h3 class="text-lg font-bold text-slate-900">No hay pedidos despachados en estas fechas</h3>
                <p class="mt-1 text-sm text-slate-500">Solo cuentan los pedidos que ya terminaron picking y packing.</p>
            </section>
        @else
            <section aria-label="Totales" class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <div class="rounded-2xl bg-white px-4 py-3 shadow-sm ring-1 ring-slate-200">
                    <div class="text-[11px] font-bold uppercase tracking-wider text-slate-500">{{ $porRuta ? 'Rutas' : 'Clientes' }}</div>
                    <div class="mt-0.5 text-2xl font-black tabular-nums text-slate-900">{{ $numero($totales['grupos']) }}</div>
                </div>
                <div class="rounded-2xl bg-white px-4 py-3 shadow-sm ring-1 ring-slate-200">
                    <div class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Pedidos</div>
                    <div class="mt-0.5 text-2xl font-black tabular-nums text-slate-900">{{ $numero($totales['pedidos']) }}</div>
                </div>
                <div class="rounded-2xl bg-white px-4 py-3 shadow-sm ring-1 ring-slate-200">
                    <div class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Unidades despachadas</div>
                    <div class="mt-0.5 text-2xl font-black tabular-nums text-slate-900">{{ $numero($totales['despachadas']) }}</div>
                </div>
                <div class="rounded-2xl bg-white px-4 py-3 shadow-sm ring-1 ring-slate-200">
                    <div class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Nivel de servicio</div>
                    <div class="mt-0.5 text-2xl font-black tabular-nums {{ $colorServicio($servicio) }}">{{ $servicio !== null ? number_format($servicio, 1, ',', '.').' %' : '—' }}</div>
                </div>
            </section>

            <section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
                <x-tabla-desplazable etiqueta="{{ $porRuta ? 'Pedidos por ruta' : 'Pedidos por cliente' }}">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-bold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th scope="col" class="px-4 py-3">{{ $porRuta ? 'Ruta' : 'Cliente' }}</th>
                                @if ($porRuta)
                                    <th scope="col" class="px-4 py-3 text-right">Clientes</th>
                                @endif
                                <th scope="col" class="px-4 py-3 text-right">Pedidos</th>
                                <th scope="col" class="px-4 py-3 text-right">Renglones</th>
                                <th scope="col" class="px-4 py-3 text-right">Unidades pedidas</th>
                                <th scope="col" class="px-4 py-3 text-right">Despachadas</th>
                                <th scope="col" class="px-4 py-3 text-right">Renglones con falla</th>
                                <th scope="col" class="px-4 py-3 text-right">Servicio</th>
                                <th scope="col" class="px-4 py-3 text-right">Tiempo promedio</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($filas as $fila)
                                <tr class="hover:bg-slate-50">
                                    <th scope="row" class="px-4 py-2.5 text-left align-top">
                                        <div class="font-semibold text-slate-800">{{ $fila['nombre'] }}</div>
                                        @unless ($porRuta)
                                            <div class="text-xs font-normal text-slate-500">{{ $fila['clave'] }}@if ($fila['ruta']) · {{ $fila['ruta'] }}@endif</div>
                                        @endunless
                                    </th>
                                    @if ($porRuta)
                                        <td class="px-4 py-2.5 text-right tabular-nums">{{ $numero($fila['clientes']) }}</td>
                                    @endif
                                    <td class="px-4 py-2.5 text-right font-bold tabular-nums text-slate-900">{{ $numero($fila['pedidos']) }}</td>
                                    <td class="px-4 py-2.5 text-right tabular-nums">{{ $numero($fila['renglones']) }}</td>
                                    <td class="px-4 py-2.5 text-right tabular-nums">{{ $numero($fila['pedidas']) }}</td>
                                    <td class="px-4 py-2.5 text-right tabular-nums">{{ $numero($fila['despachadas']) }}</td>
                                    <td class="px-4 py-2.5 text-right tabular-nums {{ $fila['fallas'] > 0 ? 'font-semibold text-rose-700' : 'text-slate-500' }}">{{ $numero($fila['fallas']) }}</td>
                                    <td class="px-4 py-2.5 text-right font-bold tabular-nums {{ $colorServicio($fila['servicio']) }}">{{ $fila['servicio'] !== null ? number_format($fila['servicio'], 1, ',', '.').' %' : '—' }}</td>
                                    <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums">{{ $t($fila['tiempo']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-tabla-desplazable>
            </section>

            {{ $filas->links() }}

            <p class="text-xs text-slate-500">
                Servicio: unidades despachadas sobre las pedidas. Tiempo promedio: desde que el pedido llegó hasta que quedó listo para facturar.
            </p>
        @endif
    </div>
</x-layouts.app>
