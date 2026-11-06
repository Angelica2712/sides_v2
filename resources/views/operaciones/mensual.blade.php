@php
    use App\Services\Informes\OperacionesService;

    $t = fn (?int $s) => OperacionesService::texto($s);
    $numero = fn ($valor, int $decimales = 0) => $valor === null ? '—' : number_format((float) $valor, $decimales, ',', '.');
    $meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    $nombreMes = fn ($fecha) => $meses[$fecha->month - 1].' '.$fecha->year;
    $cfg = auth()->user()->cfg;

    /** Cambio contra el mes anterior: [texto, ¿mejoró?] o null. $menosEsMejor para los tiempos. */
    $cambio = function ($ahora, $antes, bool $menosEsMejor = false) {
        if ($ahora === null || $antes === null || (float) $antes == 0.0) {
            return null;
        }
        $porcentaje = round(100 * ((float) $ahora - (float) $antes) / (float) $antes);
        if ($porcentaje == 0) {
            return ['igual que el mes anterior', null];
        }

        return [($porcentaje > 0 ? '▲ ' : '▼ ').abs($porcentaje).' % vs mes anterior', $menosEsMejor ? $porcentaje < 0 : $porcentaje > 0];
    };
    $colorCambio = fn (?array $c) => match (true) {
        $c === null || $c[1] === null => 'text-slate-500',
        $c[1] => 'text-emerald-700',
        default => 'text-rose-700',
    };

    $a = $actual['desempeno'];
    $p = $previo['desempeno'];
    $indicadores = [
        ['Pedidos despachados', $numero($a['pedidos']), $cambio($a['pedidos'], $p['pedidos'])],
        ['Renglones', $numero($a['renglones']), $cambio($a['renglones'], $p['renglones'])],
        ['Unidades', $numero($a['unidades']), $cambio($a['unidades'], $p['unidades'])],
        ['Nivel de servicio', $a['servicio'] !== null ? $numero($a['servicio'], 1).' %' : '—', $cambio($a['servicio'], $p['servicio'])],
        ['Renglones por hora (picking)', $numero($a['renglonesHoraPicking'], 1), $cambio($a['renglonesHoraPicking'], $p['renglonesHoraPicking'])],
        ...($conPacking ? [['Renglones por hora (packing)', $numero($a['renglonesHoraPacking'], 1), $cambio($a['renglonesHoraPacking'], $p['renglonesHoraPacking'])]] : []),
        ['Unidades que faltaron', $numero($fallas->faltante), $cambio((int) $fallas->faltante, (int) $fallasPrevio->faltante, true)],
        ['Pedidos demorados (+'.OperacionesService::MINUTOS_DEMORA.' min)', $numero($demorados), null],
    ];
@endphp

<x-layouts.app titulo="Resumen del mes">
    <div class="mx-auto max-w-5xl space-y-4">
        @include('operaciones.partials.encabezado', [
            'actual' => 'mensual',
            'titulo' => 'Resumen del mes',
            'detalle' => 'Los números del mes contra el mes anterior, en una página para imprimir o guardar en PDF.',
            'accion' => route('operaciones.mensual'),
            'extra' => '<label class="text-xs font-semibold text-slate-600">Mes<input type="month" name="mes" value="'.$mes->format('Y-m').'" max="'.now()->format('Y-m').'" required class="mt-1 block rounded-xl border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-primary focus:ring-primary"></label>',
        ])

        <div class="flex justify-end print:hidden">
            <button type="button" onclick="window.print()"
                    class="flex items-center gap-1.5 rounded-xl bg-slate-900 px-4 py-2 text-sm font-bold text-white hover:bg-slate-800">
                <svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! \App\Support\IconosSvg::path('download') !!}</svg>
                Imprimir o guardar PDF
            </button>
        </div>

        <article class="space-y-5 rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 print:p-0 print:shadow-none print:ring-0">
            <header class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-200 pb-4">
                <div class="flex items-center gap-3">
                    @if ($cfg?->urlLogo())
                        <img src="{{ $cfg->urlLogo() }}" alt="" class="h-12 w-auto">
                    @endif
                    <div>
                        <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Resumen de operaciones del almacén</p>
                        <h3 class="text-2xl font-black capitalize text-slate-900">{{ $nombreMes($mes) }}</h3>
                    </div>
                </div>
                <p class="text-sm text-slate-500">
                    @if ($enCurso)
                        Mes en curso, hasta hoy {{ now()->format('d-m-Y') }}. Se compara con los mismos días de {{ $nombreMes($anterior) }}.
                    @else
                        Comparado con {{ $nombreMes($anterior) }}.
                    @endif
                </p>
            </header>

            <section aria-labelledby="mensual-tiempos" class="space-y-2">
                <h4 id="mensual-tiempos" class="text-xs font-bold uppercase tracking-wider text-slate-500">Tiempos promedio</h4>
                <div class="grid grid-cols-2 gap-3 {{ count($tramos) === 4 ? 'md:grid-cols-4' : 'md:grid-cols-3' }}">
                    @foreach ($tramos as $tramo)
                        @php $c = $cambio($actual['tiempos'][$tramo]['segundos'], $previo['tiempos'][$tramo]['segundos'], true); @endphp
                        <div class="rounded-xl bg-slate-50 px-4 py-3 ring-1 ring-slate-200 print:break-inside-avoid">
                            <div class="text-[11px] font-bold uppercase tracking-wider text-slate-500">{{ OperacionesService::NOMBRES_ETAPA[$tramo] }}</div>
                            <div class="mt-0.5 text-2xl font-black tabular-nums text-slate-900">{{ $t($actual['tiempos'][$tramo]['segundos']) }}</div>
                            <div class="text-xs font-semibold {{ $colorCambio($c) }}">{{ $c[0] ?? 'Antes: '.$t($previo['tiempos'][$tramo]['segundos']) }}</div>
                        </div>
                    @endforeach
                </div>
            </section>

            <section aria-labelledby="mensual-desempeno" class="space-y-2">
                <h4 id="mensual-desempeno" class="text-xs font-bold uppercase tracking-wider text-slate-500">Desempeño</h4>
                <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                    @foreach ($indicadores as [$etiqueta, $valor, $c])
                        <div class="rounded-xl bg-slate-50 px-4 py-3 ring-1 ring-slate-200 print:break-inside-avoid">
                            <div class="text-[11px] font-bold uppercase tracking-wider text-slate-500">{{ $etiqueta }}</div>
                            <div class="mt-0.5 text-2xl font-black tabular-nums text-slate-900">{{ $valor }}</div>
                            @if ($c)
                                <div class="text-xs font-semibold {{ $colorCambio($c) }}">{{ $c[0] }}</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>

            <div class="grid gap-4 {{ $conPacking ? 'md:grid-cols-3' : 'md:grid-cols-2' }}">
                @foreach (array_filter([
                    'Más renglones en picking' => $actual['despachadores'],
                    'Más renglones en packing' => $conPacking ? $actual['embaladores'] : null,
                ], fn ($lista) => $lista !== null) as $titulo => $lista)
                    <section class="space-y-2 print:break-inside-avoid">
                        <h4 class="text-xs font-bold uppercase tracking-wider text-slate-500">{{ $titulo }}</h4>
                        @forelse ($lista as $i => $o)
                            <div class="flex items-center justify-between gap-2 border-b border-slate-100 py-1.5 text-sm">
                                <span class="min-w-0 truncate font-semibold text-slate-800">{{ $i + 1 }}. {{ $o['nombre'] }}</span>
                                <span class="shrink-0 tabular-nums text-slate-600">{{ $numero($o['renglones']) }} ren · {{ $numero($o['pedidos']) }} ped</span>
                            </div>
                        @empty
                            <p class="text-sm text-slate-500">Sin registros este mes.</p>
                        @endforelse
                    </section>
                @endforeach

                <section class="space-y-2 print:break-inside-avoid">
                    <h4 class="text-xs font-bold uppercase tracking-wider text-slate-500">Clientes con más pedidos</h4>
                    @forelse ($clientes as $i => $c)
                        <div class="flex items-center justify-between gap-2 border-b border-slate-100 py-1.5 text-sm">
                            <span class="min-w-0 truncate font-semibold text-slate-800">{{ $i + 1 }}. {{ $c['nombre'] }}</span>
                            <span class="shrink-0 tabular-nums text-slate-600">{{ $numero($c['pedidos']) }} ped · {{ $c['servicio'] !== null ? $numero($c['servicio'], 1).' %' : '—' }}</span>
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">Sin pedidos despachados este mes.</p>
                    @endforelse
                </section>
            </div>

            <p class="border-t border-slate-200 pt-3 text-xs text-slate-500">
                Tiempos: aceptar es desde que el pedido llega hasta que lo toma un despachador; picking, hasta que termina de recogerlo@if ($conPacking); packing, hasta que queda empacado@endif.
                Nivel de servicio: unidades despachadas sobre las pedidas. Generado por SIDES el {{ now()->format('d-m-Y H:i') }}.
            </p>
        </article>
    </div>
</x-layouts.app>
