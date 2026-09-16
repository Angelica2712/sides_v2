@php
    use App\Support\Monitor\TiemposPedido;

    // Opciones de sides_cfg que ya usaba el monitor legacy.
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

<x-layouts.app titulo="Monitor">
    <div class="space-y-4 fullscreen:overflow-y-auto fullscreen:bg-page fullscreen:p-6"
         x-data="{
             vista: localStorage.getItem('sidesMonitorVista') || 'tablero',
             segundos: 60,
             pausado: false,
             pantallaCompleta: false,
             alternarPantalla() {
                 document.fullscreenElement ? document.exitFullscreen() : this.$root.requestFullscreen();
             },
         }"
         x-init="
             $watch('vista', v => localStorage.setItem('sidesMonitorVista', v));
             document.addEventListener('fullscreenchange', () => pantallaCompleta = !!document.fullscreenElement);
             setInterval(() => { if (!pausado && --segundos <= 0) location.reload() }, 1000);
         ">

        {{-- Barra de herramientas --}}
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-xl font-extrabold text-slate-900">Pedidos en proceso</h2>
                <p class="text-sm text-slate-500">
                    {{ $cfg?->nombre }} · actualizado a las {{ $actualizado->format('H:i') }}
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <div role="group" aria-label="Tipo de vista" class="inline-flex rounded-xl bg-white p-1 shadow-sm ring-1 ring-slate-200">
                    <button type="button" @click="vista = 'tablero'" :aria-pressed="vista === 'tablero'"
                            :class="vista === 'tablero' ? 'bg-primary text-white shadow-sm' : 'text-slate-600 hover:text-slate-900'"
                            class="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-semibold transition-colors">
                        <svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="6" height="16" x="3" y="4" rx="1.5" /><rect width="6" height="10" x="10" y="4" rx="1.5" /><rect width="5" height="13" x="17" y="4" rx="1.5" /></svg>
                        Tablero
                    </button>
                    <button type="button" @click="vista = 'tabla'" :aria-pressed="vista === 'tabla'"
                            :class="vista === 'tabla' ? 'bg-primary text-white shadow-sm' : 'text-slate-600 hover:text-slate-900'"
                            class="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-semibold transition-colors">
                        <svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 5h18M3 12h18M3 19h18" /></svg>
                        Tabla
                    </button>
                </div>

                {{-- Anillo que se vacía hasta la próxima actualización automática. --}}
                <button type="button" @click="pausado = !pausado; segundos = 60"
                        :title="pausado ? 'Actualización automática en pausa' : 'Se actualiza en ' + segundos + ' segundos'"
                        :aria-label="pausado ? 'Reanudar actualización automática' : 'Pausar actualización automática; se actualiza en ' + segundos + ' segundos'"
                        class="flex items-center gap-2 rounded-xl bg-white py-1.5 pl-1.5 pr-3 text-sm font-semibold text-slate-700 shadow-sm ring-1 ring-slate-200 hover:bg-slate-50">
                    <span class="relative flex size-7 items-center justify-center" aria-hidden="true">
                        <svg class="absolute inset-0 size-7 -rotate-90" viewBox="0 0 36 36">
                            <circle cx="18" cy="18" r="15.5" fill="none" stroke-width="3" class="stroke-slate-200" />
                            <circle cx="18" cy="18" r="15.5" fill="none" stroke-width="3" stroke-linecap="round"
                                    pathLength="100" stroke-dasharray="100"
                                    :stroke-dashoffset="100 - (segundos / 60 * 100)"
                                    :class="pausado ? 'stroke-amber-400' : 'stroke-primary'"
                                    class="stroke-primary transition-[stroke-dashoffset] duration-1000 ease-linear" />
                        </svg>
                        <span x-show="!pausado" x-text="segundos" class="text-[10px] font-black text-slate-700 tabular-nums">60</span>
                        <svg x-show="pausado" x-cloak class="size-3 text-amber-600" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M7 4l13 8-13 8z" /></svg>
                    </span>
                    <span x-text="pausado ? 'Reanudar' : 'Pausar'">Pausar</span>
                </button>

                <button type="button" @click="alternarPantalla()"
                        class="flex items-center gap-1.5 rounded-xl bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm ring-1 ring-slate-200 hover:bg-slate-50">
                    <svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3" /></svg>
                    <span x-text="pantallaCompleta ? 'Salir de pantalla completa' : 'Pantalla completa'">Pantalla completa</span>
                </button>
            </div>
        </div>

        {{-- Flujo del pedido --}}
        @if ($verIndicadores)
            <section aria-label="Flujo de pedidos" class="@container rounded-2xl bg-white p-2 shadow-sm ring-1 ring-slate-200">
                <ol class="grid grid-cols-2 gap-2 @2xl:grid-cols-3 @5xl:grid-cols-5">
                    @foreach ($indicadores as $indicador)
                        <li class="relative flex items-center gap-3 rounded-xl px-4 py-3 {{ $loop->last ? 'bg-emerald-50' : 'bg-slate-50' }}">
                            <span class="flex size-11 shrink-0 items-center justify-center rounded-xl text-white shadow-sm {{ $loop->last ? 'bg-emerald-500' : 'bg-primary' }}">
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

        @if ($pedidos->isEmpty())
            <section class="rounded-2xl bg-white p-12 text-center shadow-sm ring-1 ring-slate-200">
                <span class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-600">
                    <svg class="size-7" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! \App\Support\IconosSvg::path('check') !!}</svg>
                </span>
                <h2 class="mt-4 text-lg font-bold text-slate-900">No hay pedidos en proceso</h2>
                <p class="mt-1 text-sm text-slate-500">Cuando SEPED apruebe un pedido para esta sucursal, aparecerá aquí.</p>
            </section>
        @else
            {{-- Vista tablero --}}
            {{-- Columnas con ancho mínimo: llenan pantallas grandes y se desplazan de lado en las angostas. --}}
            <div x-show="vista === 'tablero'" class="relative grid auto-cols-[minmax(19rem,1fr)] grid-flow-col items-start gap-4 overflow-x-auto pb-2">
                @foreach ($columnas as $estado => $lista)
                    @php
                        $meta = $columnasMeta[$estado];
                        $masAntiguo = $lista->map(fn ($p) => TiemposPedido::enEstadoActual($estado, $tiempos[$p->id]))->max();
                    @endphp
                    <section aria-labelledby="columna-{{ $estado }}" class="flex min-w-0 flex-col rounded-2xl bg-slate-200/60 ring-1 ring-slate-300/50">
                        <header class="px-4 pb-3 pt-4">
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

                        <div class="space-y-2.5 px-3 pb-3">
                            @forelse ($lista as $pedido)
                                @php
                                    $tiempo = $tiempos[$pedido->id];
                                    $enEtapa = TiemposPedido::enEstadoActual($pedido->estado, $tiempo);
                                    $nivel = $semaforo[TiemposPedido::nivel($enEtapa)];
                                @endphp
                                <article class="rounded-xl border-l-4 bg-white p-4 shadow-sm ring-1 ring-slate-200/80 transition-shadow hover:shadow-md {{ $nivel['borde'] }}">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <p class="truncate text-xs font-bold uppercase tracking-wide text-slate-500">{{ $pedido->ruta ?: 'Sin ruta' }}</p>
                                            <p class="font-black leading-tight text-slate-900 tabular-nums" style="font-size: {{ $tamNumeroPedido }}px">#{{ $pedido->id }}</p>
                                        </div>
                                        <span class="inline-flex shrink-0 items-center gap-1 rounded-full px-2.5 py-1 text-xs font-bold ring-1 tabular-nums {{ $nivel['chip'] }}"
                                              title="{{ $nivel['texto'] }}: {{ TiemposPedido::formatear($enEtapa) }} {{ mb_strtolower($meta['titulo']) }}">
                                            <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! \App\Support\IconosSvg::path('clock') !!}</svg>
                                            {{ TiemposPedido::formatear($enEtapa) }}
                                            <span class="sr-only">({{ $nivel['texto'] }})</span>
                                        </span>
                                    </div>

                                    <p class="mt-1.5 truncate text-sm font-semibold text-slate-700" title="{{ $pedido->nomcli }}">{{ $pedido->nomcli }}</p>
                                    <p class="text-xs text-slate-400">{{ $pedido->codcli }} · enviado {{ $fecha($pedido->fecenviado) }}</p>

                                    <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1.5 border-t border-slate-100 pt-3 text-xs font-semibold text-slate-600">
                                        <span class="tabular-nums">{{ $numero($pedido->numren) }} renglones</span>
                                        <span class="text-slate-300" aria-hidden="true">•</span>
                                        <span class="tabular-nums">{{ $numero($pedido->numund) }} unidades</span>
                                        @if ($pedido->recipiente)
                                            <span class="rounded-md bg-slate-100 px-1.5 py-0.5 text-slate-700">Recipiente {{ $pedido->recipiente }}</span>
                                        @endif
                                        @if ($verTransporte && $pedido->codtransp)
                                            <span class="rounded-md bg-slate-100 px-1.5 py-0.5 text-slate-700">Transporte {{ $pedido->codtransp }}</span>
                                        @endif
                                    </div>

                                    @if ($verDespachador && $pedido->despachador)
                                        <p class="mt-2 flex items-center gap-2 text-xs font-semibold text-slate-600">
                                            <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-primary-soft text-[11px] font-black text-primary" aria-hidden="true">
                                                {{ mb_strtoupper(mb_substr($pedido->despachador, 0, 1)) }}
                                            </span>
                                            <span class="truncate">{{ $pedido->despachador }}</span>
                                        </p>
                                    @endif

                                    @if ($verObservacion && $pedido->observacion)
                                        <p class="mt-2 rounded-lg bg-amber-50 px-2.5 py-1.5 text-xs text-amber-800">{{ $pedido->observacion }}</p>
                                    @endif

                                    @if ($pedido->estado !== 'RECIBIDO')
                                        <p class="mt-2 text-[11px] text-slate-400">
                                            Esperó {{ TiemposPedido::formatear($tiempo['espera']) }}
                                            @if ($pedido->estado === 'PACKING')
                                                · picking {{ TiemposPedido::formatear($tiempo['picking']) }}
                                            @endif
                                        </p>
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
                    <table class="min-w-full divide-y divide-slate-200" style="font-size: {{ $tamLetra }}px">
                        <thead class="bg-slate-50 text-left text-xs font-bold uppercase tracking-wide text-slate-500">
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
                                        <div class="font-extrabold">{{ $pedido->ruta ?: 'S/RUTA' }}</div>
                                        <div class="max-w-72 truncate text-[0.6em] font-medium text-slate-500" title="{{ $pedido->nomcli }}">
                                            {{ $pedido->codcli }} · {{ $pedido->nomcli }}
                                        </div>
                                    </td>
                                    <td class="px-3 py-2 align-top font-extrabold text-rose-600 tabular-nums">{{ $pedido->id }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 align-top tabular-nums">{{ $fecha($pedido->fecenviado) }}</td>
                                    <td class="px-3 py-2 text-right align-top tabular-nums">{{ $numero($pedido->numren) }}</td>
                                    <td class="px-3 py-2 text-right align-top tabular-nums">{{ $numero($pedido->numund) }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 align-top tabular-nums">{{ $fecha($pedido->fecprocesado) }}</td>
                                    <td class="px-3 py-2 align-top">
                                        <span class="inline-flex rounded-full border px-2.5 py-0.5 text-[0.7em] font-bold {{ $estilosEstado[$pedido->estado] ?? 'border-slate-200 bg-slate-100 text-slate-700' }}">
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
                                        <td class="px-3 py-2 align-top text-[0.7em] font-medium">{{ $pedido->observacion }}</td>
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
    </div>
</x-layouts.app>
