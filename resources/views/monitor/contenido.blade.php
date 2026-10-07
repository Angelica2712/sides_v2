{{--
    Parte viva del monitor: la vuelve a pedir resources/js/monitor.js (MonitorController@contenido)
    cada vez que llega un aviso por el canal sides-monitor.{codisb}, y reemplaza este bloque dentro
    de #monitor-contenido. Por eso acá no puede haber estado propio de Alpine: `vista` vive en el
    x-data de monitor/index.blade.php, que no se reemplaza.
--}}
@php
use App\Support\Monitor\TiemposPedido;

// Opciones de sides_cfg que ya usaba el monitor legacy.
// $tamLetra es la letra de la vista Tabla; sus textos secundarios (cliente, estado, encabezados) van
// en proporción a ella, con 12 px de piso.
$tamLetra = max(12, min(40, (int) ($cfg?->TamLetraMonitor ?: 14)));
$tamNumeroPedido = max(18, min(36, $tamLetra));
$conPacking = (bool) ($cfg?->activarPacking ?? true);
$verIndicadores = (bool) ($cfg?->MostrarTituloMonitor ?? true);
$verDespachador = (bool) $cfg?->activarVerOperadorMonitor;
$verObservacion = (bool) $cfg?->mostrarObsMonitor;
$verTransporte = (bool) $cfg?->mostrarTranMonitor;

$columnasMeta = [
    'RECIBIDO' => ['titulo' => 'Recibidos', 'subtitulo' => 'Esperando picking', 'punto' => 'bg-slate-400'],
    'PICKING' => ['titulo' => 'En picking', 'subtitulo' => 'Recolectando productos', 'punto' => 'bg-amber-400'],
    'PACKING' => ['titulo' => 'En packing', 'subtitulo' => 'Verificando y embalando', 'punto' => 'bg-primary'],
];

$semaforo = [
    'normal' => ['chip' => 'bg-emerald-50 text-emerald-700 ring-emerald-200', 'borde' => 'border-l-emerald-400', 'texto' => 'En tiempo'],
    'atencion' => ['chip' => 'bg-amber-50 text-amber-800 ring-amber-200', 'borde' => 'border-l-amber-400', 'texto' => 'Atención'],
    'demorado' => ['chip' => 'bg-rose-50 text-rose-700 ring-rose-200', 'borde' => 'border-l-rose-500', 'texto' => 'Demorado'],
];
$estilosEstado = [
    'RECIBIDO' => 'border-slate-200 bg-slate-100 text-slate-700',
    'PICKING' => 'border-amber-200 bg-amber-50 text-amber-700',
    'PACKING' => 'border-blue-200 bg-blue-50 text-blue-700',
];
$fecha = fn ($valor) => $valor ? \Illuminate\Support\Carbon::parse($valor)->format('d-m-y H:i') : '—';
$numero = fn ($valor) => number_format((int) $valor, 0, ',', '.');
@endphp

{{-- Flujo del pedido --}}
@if ($verIndicadores)
    <section aria-label="Flujo de pedidos" class="@container rounded-2xl bg-white p-2 shadow-sm ring-1 ring-slate-200">
        <ol class="grid grid-cols-2 gap-2 @2xl:grid-cols-3 @5xl:grid-cols-5">
            @foreach ($indicadores as $indicador)
                <li class="relative flex items-center gap-3 rounded-xl px-4 py-3 {{ $loop->last ? 'bg-emerald-50' : 'bg-slate-50' }}">
                    <span class="flex size-11 shrink-0 items-center justify-center rounded-xl shadow-sm {{ $loop->last ? 'bg-emerald-500 text-white' : 'bg-primary text-on-primary' }}">
                        <svg class="size-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            {!! \App\Support\IconosSvg::path($indicador['icono']) !!}
                        </svg>
                    </span>
                    <div class="min-w-0">
                        <div class="truncate text-[11px] font-bold uppercase tracking-wider text-slate-500">{{ $indicador['etiqueta'] }}</div>
                        <div class="mt-0.5 text-3xl font-black leading-none text-slate-900 tabular-nums">{{ $numero($indicador['valor']) }}</div>
                    </div>
                    @unless ($loop->last)
                        <span class="absolute -right-3 top-1/2 z-10 hidden size-6 -translate-y-1/2 items-center justify-center rounded-full bg-white text-slate-400 shadow-sm ring-1 ring-slate-200 @5xl:flex" aria-hidden="true">
                            <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6" /></svg>
                        </span>
                    @endunless
                </li>
            @endforeach
        </ol>
    </section>
@endif

{{-- Filtros con nombre (se administran en Filtro monitor). Son enlaces con ?filtro={id}: el
     filtro elegido queda en la URL y monitor.js la reenvía al pedir este fragmento. --}}
@if ($pestanas)
    <nav aria-label="Filtros del monitor" class="flex gap-2 overflow-x-auto pb-1">
        @foreach ($pestanas as $pestana)
            @php $activa = $pestana['id'] === $filtro?->id; @endphp
            <a href="{{ route('monitor.index', array_filter(['filtro' => $pestana['id']])) }}"
               @if ($activa) aria-current="page" @endif
               class="flex shrink-0 items-center gap-2 rounded-xl px-3.5 py-2 text-sm font-bold shadow-sm ring-1 transition-colors {{ $activa ? 'bg-primary text-on-primary ring-primary' : 'bg-white text-slate-700 ring-slate-200 hover:bg-slate-50' }}">
                {{ $pestana['nombre'] }}
                <span class="rounded-full px-2 py-0.5 text-xs font-black tabular-nums {{ $activa ? 'bg-on-primary/20 text-on-primary' : 'bg-slate-100 text-slate-700' }}">{{ $numero($pestana['total']) }}</span>
            </a>
        @endforeach
    </nav>
@endif

@if ($pedidos->isEmpty())
    <section class="rounded-2xl bg-white p-12 text-center shadow-sm ring-1 ring-slate-200">
        <span class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-600">
            <svg class="size-7" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! \App\Support\IconosSvg::path('check') !!}</svg>
        </span>
        @if ($filtro)
            <h2 class="mt-4 text-lg font-bold text-slate-900">No hay pedidos en proceso en «{{ $filtro->descrip }}»</h2>
            <p class="mt-1 text-sm text-slate-500">Entran los pedidos cuya ruta contiene: {{ $filtro->criterio }}.</p>
        @else
            <h2 class="mt-4 text-lg font-bold text-slate-900">No hay pedidos en proceso</h2>
            <p class="mt-1 text-sm text-slate-500">Cuando SEPED apruebe un pedido para esta sucursal, aparecerá aquí.</p>
        @endif
    </section>
@else
    {{-- Vista tablero --}}
    {{-- Columnas con ancho mínimo: llenan pantallas grandes y se desplazan de lado en las angostas. --}}
    <div x-show="vista === 'tablero'" class="relative grid auto-cols-[minmax(17.5rem,1fr)] grid-flow-col items-start gap-4 overflow-x-auto pb-2">
        @foreach ($columnas as $estado => $lista)
            @php
                $meta = $columnasMeta[$estado];
                $masAntiguo = $lista->map(fn ($p) => TiemposPedido::enEstadoActual($estado, $tiempos[$p->id]))->max();
            @endphp
            <section aria-labelledby="columna-{{ $estado }}" class="flex min-w-0 flex-col rounded-2xl bg-slate-200/60 ring-1 ring-slate-300/50">
                <header class="px-3.5 pb-2.5 pt-3">
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex min-w-0 items-center gap-2">
                            <span class="size-2.5 shrink-0 rounded-full {{ $meta['punto'] }}" aria-hidden="true"></span>
                            <h3 id="columna-{{ $estado }}" class="truncate font-extrabold text-slate-900">{{ $meta['titulo'] }}</h3>
                        </div>
                        <span class="shrink-0 rounded-full bg-white px-2.5 py-0.5 text-sm font-black text-slate-800 ring-1 ring-slate-200 tabular-nums">{{ $numero($lista->count()) }}</span>
                    </div>
                    <p class="mt-1 text-xs text-slate-500">
                        {{ $meta['subtitulo'] }}
                        @if ($lista->isNotEmpty())
                            · más antiguo <span class="font-bold text-slate-700 tabular-nums">{{ TiemposPedido::formatear($masAntiguo) }}</span>
                        @endif
                    </p>
                </header>

                <div class="space-y-2 px-2.5 pb-2.5">
                    @forelse ($lista as $pedido)
                        @php
                            $tiempo = $tiempos[$pedido->id];
                            $enEtapa = TiemposPedido::enEstadoActual($pedido->estado, $tiempo);
                            $nivel = $semaforo[TiemposPedido::nivel($enEtapa)];
                        @endphp
                        {{-- Todas las tarjetas miden lo mismo, sea cual sea la etapa: lo que solo tienen las de
                             picking y packing (recipiente, operario, esperas) va en las mismas líneas, sin sumar filas. --}}
                        @php
                            $esperas = $pedido->estado === 'RECIBIDO' ? '' : ' · esperó '.TiemposPedido::formatear($tiempo['espera'])
                                .($pedido->estado === 'PACKING' ? ' · picking '.TiemposPedido::formatear($tiempo['picking']) : '');
                        @endphp
                        <article class="rounded-xl border-l-4 bg-white px-3.5 py-3 shadow-sm ring-1 ring-slate-200/80 transition-shadow hover:shadow-md {{ $nivel['borde'] }}">
                            {{-- Ruta y tiempo en una fila, número y marca de pedido partido en la siguiente: así
                                 la marca no le quita ancho al tiempo ni suma una fila. --}}
                            <div class="flex items-center justify-between gap-3">
                                <p class="flex min-w-0 items-center gap-1.5 text-xs font-bold uppercase tracking-wide text-slate-500">
                                    <span class="truncate">{{ $pedido->ruta ?: 'Sin ruta' }}</span>
                                    @foreach ($marcas[$pedido->id] as $marca)
                                        <span class="shrink-0 rounded bg-violet-100 px-1.5 py-0.5 text-[10px] font-black normal-case tracking-normal text-violet-800" title="Filtro {{ $marca['filtro'] }}">{{ $marca['texto'] }}</span>
                                    @endforeach
                                </p>
                                <span class="inline-flex shrink-0 items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-bold ring-1 tabular-nums {{ $nivel['chip'] }}"
                                      title="{{ $nivel['texto'] }}: {{ TiemposPedido::formatear($enEtapa) }} {{ mb_strtolower($meta['titulo']) }}{{ $esperas }}">
                                    <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! \App\Support\IconosSvg::path('clock') !!}</svg>
                                    {{ TiemposPedido::formatear($enEtapa) }}
                                    <span class="sr-only">({{ $nivel['texto'] }}{{ $esperas }})</span>
                                </span>
                            </div>
                            <div class="flex items-center gap-2 overflow-hidden">
                                <p class="shrink-0 font-black leading-tight text-slate-900 tabular-nums" style="font-size: {{ $tamNumeroPedido }}px">#{{ $pedido->id }}</p>
                                <x-parte-pedido :parte="$partes[$pedido->id] ?? null" />
                            </div>

                            <p class="mt-1 truncate text-sm font-semibold text-slate-700" title="{{ $pedido->nomcli }}">{{ $pedido->nomcli }}</p>
                            <p class="truncate text-xs text-slate-400">{{ $pedido->codcli }} · enviado {{ $fecha($pedido->fecenviado) }}</p>

                            <div class="mt-2 flex h-7 items-center gap-2 overflow-hidden whitespace-nowrap border-t border-slate-100 pt-1.5 text-xs font-semibold text-slate-600">
                                <span class="shrink-0 tabular-nums" title="{{ $numero($pedido->numren) }} renglones · {{ $numero($pedido->numund) }} unidades">{{ $numero($pedido->numren) }} rengl. · {{ $numero($pedido->numund) }} und.</span>
                                @if ($pedido->recipiente)
                                    <span class="shrink-0 rounded-md bg-slate-100 px-1.5 py-0.5 text-slate-700" title="Recipiente {{ $pedido->recipiente }}">Rec. {{ $pedido->recipiente }}</span>
                                @endif
                                @if ($verTransporte && $pedido->codtransp)
                                    <span class="shrink-0 rounded-md bg-slate-100 px-1.5 py-0.5 text-slate-700" title="Transporte {{ $pedido->codtransp }}">Transp. {{ $pedido->codtransp }}</span>
                                @endif
                                @if ($verDespachador && $pedido->despachador)
                                    <span class="ms-auto flex min-w-0 items-center gap-1.5" title="{{ $pedido->despachador }}">
                                        <span class="flex size-5 shrink-0 items-center justify-center rounded-full bg-primary-soft text-[10px] font-black text-primary-ink" aria-hidden="true">
                                            {{ mb_strtoupper(mb_substr($pedido->despachador, 0, 1)) }}
                                        </span>
                                        <span class="truncate">{{ $pedido->despachador }}</span>
                                    </span>
                                @endif
                            </div>

                            @if ($verObservacion && $pedido->observacion)
                                <p class="mt-2 rounded-lg bg-amber-50 px-2.5 py-1.5 text-xs text-amber-800">{{ $pedido->observacion }}</p>
                            @endif
                        </article>
                    @empty
                        <p class="rounded-xl border-2 border-dashed border-slate-300 px-4 py-8 text-center text-sm font-medium text-slate-500">Sin pedidos</p>
                    @endforelse
                </div>
            </section>
        @endforeach
    </div>

    {{-- Vista tabla --}}
    <section x-show="vista === 'tabla'" x-cloak class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
        <div class="overflow-x-auto">
            {{-- --letra-tabla la pone monitor/index cuando en esa pantalla se agrandó o achicó la letra. --}}
            <table class="min-w-full divide-y divide-slate-200" style="font-size: var(--letra-tabla, {{ $tamLetra }}px)">
                <thead class="bg-slate-50 text-left text-[max(0.75em,12px)] font-bold uppercase tracking-wide text-slate-500">
                    <tr>
                        <th scope="col" class="px-3 py-2.5">Ruta</th>
                        <th scope="col" class="px-3 py-2.5">Pedido</th>
                        <th scope="col" class="px-3 py-2.5">Enviado</th>
                        <th scope="col" class="px-3 py-2.5 text-right">Renglones</th>
                        <th scope="col" class="px-3 py-2.5 text-right">Unidades</th>
                        <th scope="col" class="px-3 py-2.5">Procesado</th>
                        <th scope="col" class="px-3 py-2.5">Estado</th>
                        <th scope="col" class="px-3 py-2.5 text-right">Espera (min)</th>
                        <th scope="col" class="px-3 py-2.5 text-right">Picking (min)</th>
                        @if ($conPacking)
                            <th scope="col" class="px-3 py-2.5 text-right">Packing (min)</th>
                        @endif
                        <th scope="col" class="px-3 py-2.5">Recipiente</th>
                        @if ($verDespachador)
                            <th scope="col" class="px-3 py-2.5">Despachador</th>
                        @endif
                        @if ($verObservacion)
                            <th scope="col" class="px-3 py-2.5">Observación</th>
                        @endif
                        @if ($verTransporte)
                            <th scope="col" class="px-3 py-2.5">Transporte</th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 font-semibold text-slate-800">
                    @foreach ($pedidos as $pedido)
                        @php
                            $tiempo = $tiempos[$pedido->id];
                            $nivelTabla = TiemposPedido::nivel(TiemposPedido::enEstadoActual($pedido->estado, $tiempo));
                            $claveEtapa = ['RECIBIDO' => 'espera', 'PICKING' => 'picking', 'PACKING' => 'packing'][$pedido->estado] ?? null;
                            $colorEtapa = ['normal' => 'text-emerald-700', 'atencion' => 'text-amber-700', 'demorado' => 'text-rose-600'][$nivelTabla];
                        @endphp
                        <tr class="even:bg-slate-50/70 hover:bg-primary-soft">
                            <td class="px-3 py-2 align-top">
                                <div class="font-extrabold">
                                    {{ $pedido->ruta ?: 'S/RUTA' }}
                                    @foreach ($marcas[$pedido->id] as $marca)
                                        <span class="ml-1 rounded bg-violet-100 px-1.5 py-0.5 align-middle text-[max(0.75em,12px)] font-black text-violet-800" title="Filtro {{ $marca['filtro'] }}">{{ $marca['texto'] }}</span>
                                    @endforeach
                                </div>
                                <div class="max-w-72 truncate text-[max(0.75em,12px)] font-medium text-slate-500" title="{{ $pedido->nomcli }}">
                                    {{ $pedido->codcli }} · {{ $pedido->nomcli }}
                                </div>
                            </td>
                            <td class="px-3 py-2 align-top font-extrabold text-rose-600 tabular-nums">
                                {{ $pedido->id }}
                                <x-parte-pedido :parte="$partes[$pedido->id] ?? null" class="block w-fit" />
                            </td>
                            <td class="whitespace-nowrap px-3 py-2 align-top tabular-nums">{{ $fecha($pedido->fecenviado) }}</td>
                            <td class="px-3 py-2 text-right align-top tabular-nums">{{ $numero($pedido->numren) }}</td>
                            <td class="px-3 py-2 text-right align-top tabular-nums">{{ $numero($pedido->numund) }}</td>
                            <td class="whitespace-nowrap px-3 py-2 align-top tabular-nums">{{ $fecha($pedido->fecprocesado) }}</td>
                            <td class="px-3 py-2 align-top">
                                <span class="inline-flex rounded-full border px-2.5 py-0.5 text-[max(0.8em,12px)] font-bold {{ $estilosEstado[$pedido->estado] ?? 'border-slate-200 bg-slate-100 text-slate-700' }}">
                                    {{ $pedido->estado }}
                                </span>
                            </td>
                            @foreach (array_filter(['espera', 'picking', $conPacking ? 'packing' : null]) as $clave)
                                <td class="px-3 py-2 text-right align-top tabular-nums {{ $clave === $claveEtapa ? $colorEtapa : '' }}">{{ $numero($tiempo[$clave]) }}</td>
                            @endforeach
                            <td class="px-3 py-2 align-top">{{ $pedido->recipiente }}</td>
                            @if ($verDespachador)
                                <td class="px-3 py-2 align-top">{{ $pedido->despachador }}</td>
                            @endif
                            @if ($verObservacion)
                                <td class="px-3 py-2 align-top text-[max(0.8em,12px)] font-medium">{{ $pedido->observacion }}</td>
                            @endif
                            @if ($verTransporte)
                                <td class="px-3 py-2 align-top">{{ $pedido->codtransp }}</td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    @if ($pedidos->hasPages())
        <div class="rounded-2xl bg-white px-4 py-3 shadow-sm ring-1 ring-slate-200">{{ $pedidos->links() }}</div>
    @endif
@endif
