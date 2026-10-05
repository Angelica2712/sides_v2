@php
    use App\Services\Informes\InformesService;
    use App\Support\Duracion;

    $textos = InformesService::textos($tipo, $vista);
    $productividad = $vista === 'productividad';
    $fechas = ['desde' => $desde->format('Y-m-d'), 'hasta' => $hasta->format('Y-m-d')];
    $numero = fn ($valor) => number_format((int) $valor, 0, ',', '.');

    // La barra compara lo mismo que ordena el ranking: tiempo por renglón o tiempo promedio.
    $clave = $productividad ? 'por_renglon' : 'promedio';
    $maximo = max(1, (float) $ranking->max($clave));

    $totales = [
        ['etiqueta' => ucfirst($textos['rol']).'es', 'valor' => $numero($ranking->count())],
        ['etiqueta' => 'Pedidos', 'valor' => $numero($ranking->sum('pedidos'))],
        ['etiqueta' => 'Renglones', 'valor' => $numero($ranking->sum('renglones'))],
        ['etiqueta' => $productividad ? 'Tiempo trabajado' : 'Tiempo inactivo', 'valor' => Duracion::texto($ranking->sum('total'))],
    ];
@endphp

<x-layouts.app :titulo="$textos['titulo']">
    <div class="mx-auto max-w-6xl space-y-4">
        @include('informes.partials.encabezado')

        <p class="text-sm text-slate-500">
            Del {{ $desde->format('d-m-Y') }} al {{ $hasta->format('d-m-Y') }}.
        </p>

        @if ($ranking->isEmpty())
            <section class="rounded-2xl bg-white p-12 text-center shadow-sm ring-1 ring-slate-200">
                <h3 class="text-lg font-bold text-slate-900">No hay registros en estas fechas</h3>
                <p class="mt-1 text-sm text-slate-500">
                    Los tiempos se registran cuando un {{ $textos['rol'] }} termina o libera un pedido.
                    @unless ($productividad)
                        La inactividad aparece desde el segundo pedido que trabaja en el día.
                    @endunless
                </p>
            </section>
        @else
            <section aria-label="Totales" class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                @foreach ($totales as $total)
                    <div class="rounded-2xl bg-white px-4 py-3 shadow-sm ring-1 ring-slate-200">
                        <div class="text-[11px] font-bold uppercase tracking-wider text-slate-500">{{ $total['etiqueta'] }}</div>
                        <div class="mt-0.5 text-2xl font-black text-slate-900 tabular-nums">{{ $total['valor'] }}</div>
                    </div>
                @endforeach
            </section>

            {{-- Gráfico: barra más corta = más rápido (productividad) o menos tiempo muerto (inactividad). --}}
            <section aria-labelledby="grafico" class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
                <h3 id="grafico" class="font-extrabold text-slate-900">
                    {{ $productividad ? 'Tiempo por renglón' : 'Tiempo inactivo promedio' }}
                </h3>
                <p class="text-xs text-slate-500">Barra más corta = {{ $productividad ? 'más rápido' : 'menos tiempo muerto' }}.</p>
                <ol class="mt-4 space-y-2">
                    @foreach ($ranking as $fila)
                        <li class="grid grid-cols-[minmax(0,10rem)_1fr_auto] items-center gap-3 text-sm sm:grid-cols-[minmax(0,14rem)_1fr_auto]">
                            <span class="truncate font-semibold text-slate-700" title="{{ $fila->nombre ?: $fila->usuario }}">
                                {{ $loop->iteration }}. {{ $fila->nombre ?: $fila->usuario }}
                            </span>
                            <span class="h-3 rounded-full bg-slate-100" aria-hidden="true">
                                <span class="block h-3 rounded-full {{ $loop->first ? 'bg-emerald-500' : 'bg-primary' }}"
                                      style="width: {{ $fila->{$clave} === null ? 0 : max(2, round((float) $fila->{$clave} / $maximo * 100)) }}%"></span>
                            </span>
                            <span class="w-24 text-right font-bold tabular-nums text-slate-800">{{ Duracion::texto($fila->{$clave}) }}</span>
                        </li>
                    @endforeach
                </ol>
            </section>

            <section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-4 py-3">
                    <h3 class="font-extrabold text-slate-900">Ranking</h3>
                    <a href="{{ route('informes.excel', [$tipo, $vista, ...$fechas]) }}"
                       class="flex items-center gap-1.5 rounded-xl bg-emerald-600 px-3 py-2 text-sm font-bold text-white hover:bg-emerald-700">
                        <svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! \App\Support\IconosSvg::path('download') !!}</svg>
                        Descargar Excel
                    </a>
                </div>
                <x-tabla-desplazable etiqueta="Ranking de operarios">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-bold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th scope="col" class="px-4 py-3">#</th>
                                <th scope="col" class="px-4 py-3">{{ ucfirst($textos['rol']) }}</th>
                                <th scope="col" class="px-4 py-3 text-right">Pedidos</th>
                                <th scope="col" class="px-4 py-3 text-right">Unidades</th>
                                <th scope="col" class="px-4 py-3 text-right">Renglones</th>
                                @if ($productividad)
                                    <th scope="col" class="px-4 py-3 text-right">Por unidad</th>
                                    <th scope="col" class="px-4 py-3 text-right">Por renglón</th>
                                @endif
                                <th scope="col" class="px-4 py-3 text-right">{{ $productividad ? 'Por pedido' : 'Promedio' }}</th>
                                <th scope="col" class="px-4 py-3 text-right">Acumulado</th>
                                <th scope="col" class="px-4 py-3"><span class="sr-only">Detalle</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 tabular-nums">
                            @foreach ($ranking as $fila)
                                <tr class="hover:bg-slate-50">
                                    <td class="px-4 py-3 font-bold text-slate-500">{{ $loop->iteration }}</td>
                                    <td class="px-4 py-3">
                                        <div class="font-semibold text-slate-800">{{ $fila->nombre ?: 'Ya no es usuario de SIDES' }}</div>
                                        <div class="text-xs text-slate-500">{{ $fila->usuario }}</div>
                                    </td>
                                    <td class="px-4 py-3 text-right">{{ $numero($fila->pedidos) }}</td>
                                    <td class="px-4 py-3 text-right">{{ $numero($fila->unidades) }}</td>
                                    <td class="px-4 py-3 text-right">{{ $numero($fila->renglones) }}</td>
                                    @if ($productividad)
                                        <td class="px-4 py-3 text-right">{{ Duracion::texto($fila->por_unidad) }}</td>
                                        <td class="px-4 py-3 text-right font-bold text-slate-900">{{ Duracion::texto($fila->por_renglon) }}</td>
                                    @endif
                                    <td class="px-4 py-3 text-right {{ $productividad ? '' : 'font-bold text-slate-900' }}">{{ Duracion::texto($fila->promedio) }}</td>
                                    <td class="px-4 py-3 text-right">{{ Duracion::texto($fila->total) }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right">
                                        @if ($fila->usuario_id)
                                            <a href="{{ route('informes.operario', [$tipo, $vista, $fila->usuario_id, ...$fechas]) }}" class="font-semibold text-primary-ink hover:underline">Ver detalle</a>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-tabla-desplazable>
            </section>
        @endif
    </div>
</x-layouts.app>
