@php
    use App\Services\Informes\OperacionesService;
    use Illuminate\Support\Carbon;

    $t = fn (?int $s) => OperacionesService::texto($s);
    $fechas = ['desde' => $desde->format('Y-m-d'), 'hasta' => $hasta->format('Y-m-d')];
    $total = $datos['totales'];
    // El tiempo más lento de cada columna se marca: es donde hay que mirar.
    $peor = fn (array $filas) => collect($tramos)->mapWithKeys(fn ($tramo) => [$tramo => collect($filas)->max(fn ($f) => $f['tiempos'][$tramo])])->all();
    $peorDia = $peor($datos['dias']);
    $peorHora = $peor($datos['horas']);
    $maxPedidosHora = max(1, collect($datos['horas'])->max('pedidos') ?? 1);
    $dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
@endphp

<x-layouts.app titulo="Tiempos por día y hora">
    <div class="mx-auto max-w-6xl space-y-4">
        @include('operaciones.partials.encabezado', [
            'actual' => 'tiempos',
            'titulo' => 'Tiempos por día y hora',
            'detalle' => 'Tiempo promedio de cada paso según el día y la hora en que llegó el pedido. En rojo, el más lento de cada columna.',
            'accion' => route('operaciones.tiempos'),
            'excel' => route('operaciones.tiempos.excel', $fechas),
        ])

        @if ($total['pedidos'] === 0)
            <section class="rounded-2xl bg-white p-12 text-center shadow-sm ring-1 ring-slate-200">
                <h3 class="text-lg font-bold text-slate-900">No llegaron pedidos en estas fechas</h3>
                <p class="mt-1 text-sm text-slate-500">Elige otras fechas.</p>
            </section>
        @else
            <section aria-label="Promedio del rango" class="grid grid-cols-2 gap-3 {{ count($tramos) === 4 ? 'lg:grid-cols-5' : 'lg:grid-cols-4' }}">
                <div class="rounded-2xl bg-white px-4 py-3 shadow-sm ring-1 ring-slate-200">
                    <div class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Pedidos llegados</div>
                    <div class="mt-0.5 text-2xl font-black tabular-nums text-slate-900">{{ number_format($total['pedidos'], 0, ',', '.') }}</div>
                </div>
                @foreach ($tramos as $tramo)
                    <div class="rounded-2xl bg-white px-4 py-3 shadow-sm ring-1 ring-slate-200">
                        <div class="text-[11px] font-bold uppercase tracking-wider text-slate-500">{{ OperacionesService::NOMBRES_ETAPA[$tramo] }} promedio</div>
                        <div class="mt-0.5 text-2xl font-black tabular-nums text-slate-900">{{ $t($total['tiempos'][$tramo]) }}</div>
                    </div>
                @endforeach
            </section>

            <section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200" aria-labelledby="por-hora">
                <div class="border-b border-slate-200 px-4 py-3">
                    <h3 id="por-hora" class="font-extrabold text-slate-900">Por hora del día</h3>
                    <p class="text-xs text-slate-500">La barra es cuántos pedidos llegaron a esa hora: las horas pico.</p>
                </div>
                <x-tabla-desplazable etiqueta="Tiempos por hora">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-bold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th scope="col" class="px-4 py-3">Hora</th>
                                <th scope="col" class="px-4 py-3">Pedidos</th>
                                @foreach ($tramos as $tramo)
                                    <th scope="col" class="px-4 py-3 text-right">{{ OperacionesService::NOMBRES_ETAPA[$tramo] }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($datos['horas'] as $fila)
                                <tr class="hover:bg-slate-50">
                                    <th scope="row" class="whitespace-nowrap px-4 py-2.5 text-left font-bold tabular-nums text-slate-800">{{ $fila['etiqueta'] }}</th>
                                    <td class="px-4 py-2.5">
                                        <div class="flex items-center gap-2">
                                            <div class="h-2 w-32 overflow-hidden rounded-full bg-slate-100">
                                                <div class="h-full rounded-full bg-primary" style="width: {{ round(100 * $fila['pedidos'] / $maxPedidosHora) }}%"></div>
                                            </div>
                                            <span class="tabular-nums font-semibold text-slate-700">{{ $fila['pedidos'] }}</span>
                                        </div>
                                    </td>
                                    @foreach ($tramos as $tramo)
                                        @php $valor = $fila['tiempos'][$tramo]; @endphp
                                        <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums {{ $valor !== null && $valor === $peorHora[$tramo] && count($datos['horas']) > 1 ? 'font-black text-rose-700' : 'text-slate-700' }}">{{ $t($valor) }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-tabla-desplazable>
            </section>

            <section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200" aria-labelledby="por-dia">
                <div class="border-b border-slate-200 px-4 py-3">
                    <h3 id="por-dia" class="font-extrabold text-slate-900">Por día</h3>
                </div>
                <x-tabla-desplazable etiqueta="Tiempos por día">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-bold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th scope="col" class="px-4 py-3">Día</th>
                                <th scope="col" class="px-4 py-3 text-right">Pedidos</th>
                                @foreach ($tramos as $tramo)
                                    <th scope="col" class="px-4 py-3 text-right">{{ OperacionesService::NOMBRES_ETAPA[$tramo] }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach (array_reverse($datos['dias']) as $fila)
                                @php $dia = Carbon::parse($fila['etiqueta']); @endphp
                                <tr class="{{ $fila['pedidos'] === 0 ? 'text-slate-400' : 'hover:bg-slate-50' }}">
                                    <th scope="row" class="whitespace-nowrap px-4 py-2.5 text-left font-semibold">
                                        <span class="tabular-nums {{ $fila['pedidos'] === 0 ? '' : 'text-slate-800' }}">{{ $dia->format('d-m-Y') }}</span>
                                        <span class="ml-1 text-xs font-normal text-slate-500">{{ $dias[$dia->dayOfWeek] }}</span>
                                    </th>
                                    <td class="px-4 py-2.5 text-right tabular-nums">{{ $fila['pedidos'] }}</td>
                                    @foreach ($tramos as $tramo)
                                        @php $valor = $fila['tiempos'][$tramo]; @endphp
                                        <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums {{ $valor !== null && $valor === $peorDia[$tramo] && count($datos['dias']) > 1 ? 'font-black text-rose-700' : '' }}">{{ $t($valor) }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-tabla-desplazable>
            </section>

            <p class="text-xs text-slate-500">
                Aceptar: desde que el pedido llega hasta que un despachador lo toma. Picking: hasta que termina de recogerlo.
                @if (in_array('packing', $tramos, true)) Packing: hasta que queda empacado. @endif
                Un paso de más de 24 horas no entra al promedio (un pedido que quedó abierto de un día para otro).
            </p>
        @endif
    </div>
</x-layouts.app>
