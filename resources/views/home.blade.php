@php
    $conPacking = (bool) ($cfg?->activarPacking ?? true);
    $tramos = array_filter([
        'aceptar' => ['Aceptar el pedido', 'Desde que llega a SIDES hasta que un despachador lo toma.'],
        'picking' => ['Picking', 'Desde que lo toman hasta que terminan de recoger los productos.'],
        'packing' => $conPacking ? ['Packing', 'Desde que sale del picking hasta que queda empacado.'] : null,
        'total' => ['Total en almacén', 'Desde que llega hasta que queda listo para facturar.'],
    ]);
@endphp
<x-layouts.app titulo="Inicio">
    <div class="max-w-7xl mx-auto space-y-6">
        <section class="rounded-2xl bg-white border border-slate-200 shadow-sm p-5 sm:p-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <h2 class="text-2xl font-extrabold text-slate-900">Hola, {{ auth()->user()->name }}</h2>
                <p class="mt-1 text-sm text-slate-500">
                    {{ $cfg?->nombre ?? 'Sucursal sin configuración' }} · Sucursal {{ auth()->user()->codisb }}
                </p>
            </div>
            @unless ($conIndicadores)
                <span class="inline-flex items-center self-start sm:self-center rounded-full bg-primary-soft px-3 py-1 text-xs font-bold text-primary-ink">
                    {{ count($modulos) }} {{ count($modulos) === 1 ? 'módulo disponible' : 'módulos disponibles' }}
                </span>
            @endunless
        </section>

        @if ($conIndicadores)
            {{-- Indicadores del almacén: los números salen de App\Services\Inicio\DesempenoAlmacen y se refrescan con resources/js/inicio.js. --}}
            <div class="space-y-6"
                 x-data="inicioIndicadores({ canal: @js('sides-monitor.'.auth()->user()->codisb), url: @js(route('home.indicadores')), periodos: @js($periodos) })">

                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h2 class="text-sm font-bold uppercase tracking-wide text-slate-500">Tiempos promedio del almacén</h2>
                        <p class="text-xs text-slate-400">
                            Cada paso cuenta en el período en que terminó. No entran los que tardaron más de 24 horas (quedaron abiertos de un día para otro).
                        </p>
                    </div>
                    <label class="flex items-center gap-2 text-sm text-slate-600">
                        <span class="font-semibold">Período</span>
                        <select x-model="periodo" class="rounded-lg border-slate-300 py-1.5 text-sm font-semibold text-slate-800 focus:border-primary focus:ring-primary">
                            @foreach ($periodos as $clave => $etiqueta)
                                <option value="{{ $clave }}">{{ $etiqueta }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>

                {{-- Tiempos promedio --}}
                <section class="grid gap-3 sm:grid-cols-2 {{ count($tramos) === 4 ? 'lg:grid-cols-4' : 'lg:grid-cols-3' }}" aria-label="Tiempos promedio">
                    @foreach ($tramos as $clave => [$titulo, $descripcion])
                        <article class="rounded-2xl bg-white p-4 shadow-sm ring-1 {{ $clave === 'total' ? 'ring-primary/40' : 'ring-slate-200' }}">
                            <p class="text-xs font-bold uppercase tracking-wide {{ $clave === 'total' ? 'text-primary-ink' : 'text-slate-500' }}">{{ $titulo }}</p>
                            <template x-if="cargando || tiempo('{{ $clave }}')?.pedidos">
                                <p class="mt-1 text-3xl font-black tabular-nums text-slate-900"
                                   x-text="cargando ? '…' : duracion(tiempo('{{ $clave }}')?.segundos ?? null)">…</p>
                            </template>
                            {{-- Sin pedidos en el período: se dice, y se muestra el último día que sí tuvo (no una raya sola). --}}
                            <template x-if="!cargando && !tiempo('{{ $clave }}')?.pedidos">
                                <div class="mt-1">
                                    <p class="text-lg font-extrabold leading-9 text-slate-400" x-text="sinPedidos()"></p>
                                    <p x-show="tiempo('{{ $clave }}')?.referencia" class="text-sm text-slate-600">
                                        Último día con pedidos (<span x-text="tiempo('{{ $clave }}')?.referencia?.fecha"></span>):
                                        <span class="font-extrabold tabular-nums text-slate-900" x-text="duracion(tiempo('{{ $clave }}')?.referencia?.segundos)"></span>
                                    </p>
                                </div>
                            </template>
                            <p class="mt-1 text-xs text-slate-500">{{ $descripcion }}</p>
                            <div class="mt-3 flex flex-wrap items-center gap-2 text-xs">
                                <span class="rounded-full bg-slate-100 px-2 py-0.5 font-semibold text-slate-600"
                                      x-text="numero(tiempo('{{ $clave }}')?.pedidos ?? 0) + ' pedidos'"></span>
                                <template x-if="cambio('{{ $clave }}')">
                                    <span class="rounded-full px-2 py-0.5 font-bold"
                                          :class="cambio('{{ $clave }}').mejor ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'"
                                          x-text="cambio('{{ $clave }}').texto"></span>
                                </template>
                            </div>
                        </article>
                    @endforeach
                </section>

                {{-- Almacén en vivo --}}
                <section class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 space-y-4" aria-labelledby="almacen-vivo">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="flex items-center gap-2">
                            <span class="relative flex size-2.5" aria-hidden="true">
                                <span class="absolute inline-flex size-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
                                <span class="relative inline-flex size-2.5 rounded-full bg-emerald-500"></span>
                            </span>
                            <h2 id="almacen-vivo" class="text-lg font-extrabold text-slate-900">Almacén en vivo</h2>
                            <span x-show="datos" class="text-xs text-slate-400">· <span x-text="datos?.hora" class="tabular-nums"></span></span>
                        </div>
                        @if ($vivo)
                            <a href="{{ route('monitor.picking') }}" class="text-sm font-semibold text-primary-ink hover:underline">Pantalla completa →</a>
                        @endif
                    </div>

                    <div class="grid gap-3 grid-cols-2 {{ $conPacking ? 'lg:grid-cols-4' : 'lg:grid-cols-3' }}">
                        <div class="rounded-xl bg-amber-50 p-3 ring-1 ring-amber-200">
                            <p class="text-xs font-bold uppercase tracking-wide text-amber-800">Por tomar</p>
                            <p class="text-2xl font-black tabular-nums text-slate-900" x-text="numero(datos?.cola.porTomar)">—</p>
                            <p class="text-xs text-amber-900" x-show="datos?.cola.esperaMasLarga !== null && datos?.cola.porTomar">
                                El más viejo espera <span class="font-bold" x-text="duracion(datos?.cola.esperaMasLarga)"></span>
                            </p>
                        </div>
                        <div class="rounded-xl bg-sky-50 p-3 ring-1 ring-sky-200">
                            <p class="text-xs font-bold uppercase tracking-wide text-sky-800">En picking</p>
                            <p class="text-2xl font-black tabular-nums text-slate-900" x-text="numero(datos?.cola.picking)">—</p>
                        </div>
                        @if ($conPacking)
                            <div class="rounded-xl bg-violet-50 p-3 ring-1 ring-violet-200">
                                <p class="text-xs font-bold uppercase tracking-wide text-violet-800">En packing</p>
                                <p class="text-2xl font-black tabular-nums text-slate-900" x-text="numero(datos?.cola.packing)">—</p>
                            </div>
                        @endif
                        <div class="rounded-xl bg-emerald-50 p-3 ring-1 ring-emerald-200">
                            <p class="text-xs font-bold uppercase tracking-wide text-emerald-800">Listos para facturar</p>
                            <p class="text-2xl font-black tabular-nums text-slate-900" x-text="numero(datos?.cola.facturar)">—</p>
                        </div>
                    </div>

                    @if ($vivo)
                        {{-- Los mismos despachadores y el mismo Almacén 3D de «Picking en vivo» (resources/js/picking-vivo.js). --}}
                        <div x-data="pickingVivo({ canal: @js('sides-monitor.'.auth()->user()->codisb), urls: @js($vivo) })"
                             class="grid grid-cols-1 items-start gap-4 {{ $vivo['plano'] ? 'lg:grid-cols-[20rem_minmax(0,1fr)]' : '' }}">
                            {{-- grid-cols-1 y min-w-0: sin eso el texto largo de «Buscando…» estira la columna y en el teléfono la página se sale de la pantalla. --}}
                            <div class="min-w-0 space-y-2 lg:max-h-[min(40rem,75vh)] lg:overflow-y-auto lg:pr-1">
                                <p x-show="cargando" class="text-sm text-slate-500">Cargando…</p>
                                <p x-show="!cargando && operarios.length === 0" x-cloak class="rounded-xl bg-slate-50 p-4 text-center text-sm text-slate-500">
                                    Nadie está haciendo picking ahora.
                                </p>
                                <template x-for="o in operarios" :key="o.pedido">
                                    <button type="button" @click="elegir(o)" :aria-pressed="elegido === o.pedido"
                                            class="block w-full rounded-xl bg-white p-3 text-left ring-1 transition hover:ring-slate-300"
                                            :class="elegido === o.pedido ? 'ring-2 ring-orange-400' : 'ring-slate-200'">
                                        <div class="flex items-center justify-between gap-2">
                                            <div class="flex min-w-0 items-center gap-2">
                                                <span class="size-2.5 shrink-0 rounded-full" :style="{ background: o.color }" aria-hidden="true"></span>
                                                <p class="truncate text-sm font-extrabold text-slate-900" x-text="o.despachador"></p>
                                            </div>
                                            <span class="shrink-0 text-xs font-bold tabular-nums text-slate-500" x-text="'#' + o.pedido"></span>
                                        </div>
                                        <div class="mt-2 flex items-center gap-2">
                                            <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-200">
                                                <div class="h-full rounded-full transition-all duration-500" :style="{ width: porcentaje(o) + '%', background: o.color }"></div>
                                            </div>
                                            <span class="shrink-0 text-xs font-bold tabular-nums text-slate-600"><span x-text="o.revisados"></span>/<span x-text="o.productos"></span></span>
                                        </div>
                                        <p x-show="o.actual" class="mt-1 truncate text-xs text-slate-500">
                                            Buscando <span class="font-mono font-bold text-orange-700" x-text="codigo(o.actual?.ubicacion)"></span> · <span x-text="o.actual?.desprod"></span>
                                        </p>
                                    </button>
                                </template>
                                <div class="pt-2">@include('monitor.partials.camiones')</div>
                            </div>

                            @if ($vivo['plano'])
                                {{-- Alto fijo en el estilo (no en una clase): el almacén se ve grande siempre, haya una o veinte personas en picking. --}}
                                <div x-show="conMapa" class="relative overflow-hidden rounded-xl ring-1 ring-slate-200"
                                     style="height: clamp(26rem, 75vh, 46rem)">
                                    <div x-ref="visor" class="absolute inset-0"></div>
                                    <p x-show="mensajeMapa" x-cloak class="absolute inset-x-0 top-12 m-3 rounded-xl bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-900 ring-1 ring-amber-200" x-text="mensajeMapa"></p>
                                    <div class="absolute left-3 top-3">
                                        <button type="button" x-show="elegido !== null" x-cloak @click="verTodo()"
                                                class="rounded-xl bg-white/95 px-3 py-2 text-sm font-bold text-slate-700 shadow ring-1 ring-slate-300 hover:bg-white">Ver todo el almacén</button>
                                    </div>
                                    @include('almacen.partials.controles-3d')
                                </div>
                            @endif
                        </div>
                    @endif
                </section>

                {{-- Desempeño del almacén --}}
                <section class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 space-y-4" aria-labelledby="desempeno">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <h2 id="desempeno" class="text-lg font-extrabold text-slate-900">Desempeño del almacén</h2>
                            <p class="text-xs text-slate-500" x-text="datos?.etiqueta"></p>
                        </div>
                        @if (\App\Support\MenuSides::puede(auth()->user(), $cfg, 'operaciones'))
                            <a href="{{ route('operaciones.index') }}" class="text-sm font-bold text-primary-ink hover:underline">Ver reportes de operaciones →</a>
                        @endif
                    </div>

                    <dl class="grid gap-3 grid-cols-2 md:grid-cols-3 {{ $conPacking ? 'xl:grid-cols-6' : 'xl:grid-cols-5' }}">
                        <div class="rounded-xl bg-slate-50 p-3">
                            <dt class="text-xs font-semibold text-slate-500">Pedidos completados</dt>
                            <dd class="text-2xl font-black tabular-nums text-slate-900" x-text="numero(datos?.desempeno.pedidos)">—</dd>
                        </div>
                        <div class="rounded-xl bg-slate-50 p-3">
                            <dt class="text-xs font-semibold text-slate-500">Renglones recogidos</dt>
                            <dd class="text-2xl font-black tabular-nums text-slate-900" x-text="numero(datos?.desempeno.renglones)">—</dd>
                        </div>
                        <div class="rounded-xl bg-slate-50 p-3">
                            <dt class="text-xs font-semibold text-slate-500">Unidades recogidas</dt>
                            <dd class="text-2xl font-black tabular-nums text-slate-900" x-text="numero(datos?.desempeno.unidades)">—</dd>
                        </div>
                        <div class="rounded-xl bg-slate-50 p-3" title="Unidades despachadas sobre las pedidas, en los pedidos completados">
                            <dt class="text-xs font-semibold text-slate-500">Nivel de servicio</dt>
                            <dd class="text-2xl font-black tabular-nums"
                                :class="(datos?.desempeno.servicio ?? null) === null ? 'text-slate-900' : (datos.desempeno.servicio >= 95 ? 'text-emerald-700' : (datos.desempeno.servicio >= 85 ? 'text-amber-700' : 'text-rose-700'))"
                                x-text="(datos?.desempeno.servicio ?? null) === null ? '—' : numero(datos.desempeno.servicio) + '%'">—</dd>
                        </div>
                        <div class="rounded-xl bg-slate-50 p-3">
                            <dt class="text-xs font-semibold text-slate-500">Renglones por hora · picking</dt>
                            <dd class="text-2xl font-black tabular-nums text-slate-900" x-text="numero(datos?.desempeno.renglonesHoraPicking)">—</dd>
                        </div>
                        @if ($conPacking)
                            <div class="rounded-xl bg-slate-50 p-3">
                                <dt class="text-xs font-semibold text-slate-500">Renglones por hora · packing</dt>
                                <dd class="text-2xl font-black tabular-nums text-slate-900" x-text="numero(datos?.desempeno.renglonesHoraPacking)">—</dd>
                            </div>
                        @endif
                    </dl>

                    <div class="grid gap-4 {{ $conPacking ? 'md:grid-cols-2' : '' }}">
                        @foreach (array_filter(['despachadores' => 'Despachadores con más renglones', 'embaladores' => $conPacking ? 'Embaladores con más renglones' : null]) as $lista => $titulo)
                            <div>
                                <h3 class="mb-2 text-sm font-bold uppercase tracking-wide text-slate-500">{{ $titulo }}</h3>
                                <p x-show="datos && datos.{{ $lista }}.length === 0" class="py-2 text-sm text-slate-400">Sin movimientos en el período.</p>
                                <ol class="divide-y divide-slate-100">
                                    <template x-for="(fila, i) in datos?.{{ $lista }} ?? []" :key="fila.nombre">
                                        <li class="flex items-center gap-3 py-2">
                                            <span class="flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-black"
                                                  :class="i === 0 ? 'bg-primary text-on-primary' : 'bg-slate-100 text-slate-600'" x-text="i + 1"></span>
                                            <span class="min-w-0 flex-1 truncate text-sm font-semibold text-slate-800" x-text="fila.nombre"></span>
                                            <span class="shrink-0 text-xs text-slate-500 tabular-nums">
                                                <span class="font-bold text-slate-800" x-text="numero(fila.renglones)"></span> reng. ·
                                                <span x-text="numero(fila.pedidos)"></span> ped.
                                            </span>
                                        </li>
                                    </template>
                                </ol>
                            </div>
                        @endforeach
                    </div>
                </section>
            </div>
        @endif

        {{-- Con indicadores el Inicio es solo el almacén (los módulos están en el menú); quien no los ve, sigue viendo sus módulos aquí. --}}
        @if ($conIndicadores)
        @elseif (empty($modulos))
            <section class="rounded-2xl bg-white border border-slate-200 shadow-sm p-8 text-center">
                <h2 class="text-lg font-bold text-slate-900">No tienes módulos habilitados</h2>
                <p class="mt-1 text-sm text-slate-500">Pide a un administrador de SIDES que te asigne permisos.</p>
            </section>
        @else
            @foreach ($secciones as $seccion => $modulosSeccion)
            <section aria-labelledby="seccion-{{ $loop->index }}">
                <h2 id="seccion-{{ $loop->index }}" class="mb-3 text-sm font-bold uppercase tracking-wide text-slate-500">{{ $seccion }}</h2>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($modulosSeccion as $modulo)
                        <a href="{{ route($modulo['ruta']) }}"
                           class="group flex items-start gap-3 rounded-xl bg-white border border-slate-200 p-4 shadow-sm transition hover:border-primary hover:shadow-md">
                            <span class="flex items-center justify-center size-11 shrink-0 rounded-lg bg-primary-soft text-primary-ink transition-colors group-hover:bg-primary group-hover:text-on-primary">
                                <svg class="size-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    {!! \App\Support\IconosSvg::path($modulo['icono']) !!}
                                </svg>
                            </span>
                            <span class="min-w-0">
                                <span class="block font-bold text-slate-900">{{ $modulo['etiqueta'] }}</span>
                                <span class="block text-sm text-slate-500">{{ $modulo['descripcion'] }}</span>
                            </span>
                        </a>
                    @endforeach
                </div>
            </section>
            @endforeach
        @endif
    </div>
</x-layouts.app>
