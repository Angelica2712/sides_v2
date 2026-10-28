<x-layouts.app titulo="Picking en vivo">
    {{--
        Picking en vivo: cada despachador con su avance y su lugar en el Almacén 3D. Se actualiza
        sola con el canal sides-monitor.{codisb}; la lógica está en resources/js/picking-vivo.js.
    --}}
    <div class="space-y-4" x-data="pickingVivo({ canal: @js('sides-monitor.'.$codisb), urls: @js($urls) })">

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <a href="{{ route('monitor.index') }}" class="text-sm font-semibold text-primary-ink hover:underline">← Monitor</a>
                <h2 class="text-xl font-extrabold text-slate-900">Picking en vivo</h2>
                <p class="text-sm text-slate-500">
                    {{ $cfg?->nombre }}<span x-show="hora"> · actualizado a las <span x-text="hora" class="tabular-nums"></span></span>
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <div class="rounded-xl bg-white px-3 py-2 text-sm shadow-sm ring-1 ring-slate-200">
                    <span class="font-black tabular-nums text-slate-900" x-text="operarios.length"></span>
                    <span class="text-slate-500" x-text="operarios.length === 1 ? 'despachador' : 'despachadores'"></span>
                </div>
                <div class="rounded-xl bg-white px-3 py-2 text-sm shadow-sm ring-1 ring-slate-200">
                    <span class="font-black tabular-nums text-slate-900"><span x-text="unidadesListas"></span>/<span x-text="unidadesEnCurso"></span></span>
                    <span class="text-slate-500">unidades</span>
                </div>
                {{-- Mismo chip de conexión que el monitor. --}}
                <div class="flex items-center gap-2 rounded-xl bg-white px-3 py-2 text-sm font-semibold shadow-sm ring-1"
                     :class="conectado ? 'text-emerald-700 ring-emerald-200' : 'text-amber-700 ring-amber-200'"
                     :title="conectado ? 'Conectado: cada producto aparece apenas se guarda' : 'Sin conexión en vivo: se consulta cada 30 segundos'"
                     aria-live="polite">
                    <span class="relative flex size-2" aria-hidden="true">
                        <span x-show="conectado" class="absolute inline-flex size-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
                        <span class="relative inline-flex size-2 rounded-full" :class="conectado ? 'bg-emerald-500' : 'bg-amber-500'"></span>
                    </span>
                    <span x-text="conectado ? (actualizando ? 'Actualizando…' : 'En vivo') : (hubo ? 'Reconectando…' : 'Conectando…')">Conectando…</span>
                </div>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-[24rem_1fr]">
            {{-- Despachadores --}}
            <section class="space-y-3" aria-label="Despachadores en picking">
                <p x-show="cargando" class="rounded-2xl bg-white p-4 text-sm text-slate-500 shadow-sm ring-1 ring-slate-200">Cargando…</p>
                <p x-show="!cargando && operarios.length === 0" x-cloak class="rounded-2xl bg-white p-6 text-center text-sm text-slate-500 shadow-sm ring-1 ring-slate-200">
                    Nadie está haciendo picking ahora. Cuando un despachador tome un pedido, aparece aquí.
                </p>
                <template x-for="o in operarios" :key="o.pedido">
                    <button type="button" @click="elegir(o)" :aria-pressed="elegido === o.pedido"
                            class="block w-full rounded-2xl bg-white p-4 text-left shadow-sm ring-1 transition hover:ring-slate-300"
                            :class="elegido === o.pedido ? 'ring-2 ring-orange-400' : 'ring-slate-200'">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex min-w-0 items-center gap-2">
                                <span class="size-3 shrink-0 rounded-full" :style="{ background: o.color }" aria-hidden="true"></span>
                                <p class="truncate font-extrabold text-slate-900" x-text="o.despachador"></p>
                            </div>
                            <span class="shrink-0 rounded-lg bg-slate-100 px-2 py-0.5 text-xs font-bold tabular-nums text-slate-700" x-text="'#' + o.pedido"></span>
                        </div>
                        <p class="mt-0.5 truncate text-xs text-slate-500">
                            <span x-text="o.cliente"></span><span x-show="o.recipiente"> · <span x-text="o.recipiente"></span></span>
                        </p>

                        <div class="mt-3 flex items-center gap-2">
                            <div class="h-2 flex-1 overflow-hidden rounded-full bg-slate-200" role="progressbar" :aria-valuenow="porcentaje(o)" aria-valuemin="0" aria-valuemax="100">
                                <div class="h-full rounded-full transition-all duration-500" :style="{ width: porcentaje(o) + '%', background: o.color }"></div>
                            </div>
                            <span class="shrink-0 text-xs font-bold tabular-nums text-slate-700"><span x-text="o.revisados"></span>/<span x-text="o.productos"></span></span>
                        </div>

                        <template x-if="o.actual">
                            <div class="mt-3 rounded-xl bg-orange-50 px-3 py-2 ring-1 ring-orange-200">
                                <p class="text-[11px] font-bold uppercase tracking-wide text-orange-700">Buscando</p>
                                <p class="font-mono text-lg font-black leading-tight text-slate-900" x-text="codigo(o.actual.ubicacion)"></p>
                                <p class="truncate text-xs text-slate-600"><span x-text="o.actual.desprod"></span> · x<span x-text="o.actual.cantidad"></span></p>
                            </div>
                        </template>
                        <p x-show="!o.actual" class="mt-3 rounded-xl bg-emerald-50 px-3 py-2 text-sm font-bold text-emerald-800 ring-1 ring-emerald-200">Revisó todo: falta terminar el picking.</p>
                        <p x-show="o.siguiente" class="mt-2 truncate text-xs text-slate-500">
                            Después: <span class="font-bold text-violet-700" x-text="codigo(o.siguiente?.ubicacion)"></span> · <span x-text="o.siguiente?.desprod"></span>
                        </p>
                    </button>
                </template>
            </section>

            <div class="min-w-0 space-y-4">
                {{-- Mapa del almacén --}}
                <section x-show="conMapa" x-ref="mapa" class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
                    <div class="relative h-[60vh] min-h-72">
                        <div x-ref="visor" class="absolute inset-0"></div>
                        <p x-show="mensajeMapa" x-cloak class="absolute inset-x-0 top-12 m-3 rounded-xl bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-900 ring-1 ring-amber-200" x-text="mensajeMapa"></p>
                        <div class="absolute left-3 top-3 flex flex-wrap gap-2">
                            <button type="button" x-show="elegido !== null" x-cloak @click="verTodo()"
                                    class="rounded-xl bg-white/95 px-3 py-2 text-sm font-bold text-slate-700 shadow ring-1 ring-slate-300 hover:bg-white">Ver todo el almacén</button>
                        </div>
                        <p class="pointer-events-none absolute bottom-2 left-3 right-44 text-xs text-slate-500"
                           x-text="elegido !== null ? 'Naranja: lo que busca ahora · morado: lo que sigue' : 'Cada color es un despachador · toca uno para seguirlo'"></p>
                        @include('almacen.partials.controles-3d')
                    </div>
                    <div x-show="seleccion" x-cloak class="flex flex-wrap items-center gap-2 border-t border-slate-200 px-4 py-3">
                        <span class="size-3 rounded-full" :style="{ background: seleccion?.color }" aria-hidden="true"></span>
                        <span class="font-bold text-slate-900" x-text="seleccion?.despachador"></span>
                        <template x-for="[nombre, valor] in detalleSeleccion" :key="nombre">
                            <span class="rounded-lg bg-orange-50 px-2 py-1 text-xs text-orange-900"><span x-text="nombre"></span> <span class="font-bold" x-text="valor"></span></span>
                        </template>
                    </div>
                </section>

                {{-- Últimos movimientos: llegan por el WebSocket y no se guardan; al abrir la pantalla empieza vacía. --}}
                <section class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                    <h3 class="mb-2 text-sm font-bold uppercase tracking-wide text-slate-500">Últimos movimientos</h3>
                    <p x-show="movimientos.length === 0" class="py-2 text-sm text-slate-400">Aquí aparece cada producto apenas el despachador guarda la cantidad.</p>
                    <ul class="divide-y divide-slate-100">
                        <template x-for="m in movimientos" :key="m.id">
                            <li class="flex items-center gap-3 py-2">
                                <span class="w-16 shrink-0 text-xs tabular-nums text-slate-400" x-text="m.hora"></span>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm text-slate-800">
                                        <span class="font-bold" x-text="m.despachador"></span>
                                        <span class="text-slate-500">· #<span x-text="m.pedido"></span> ·</span>
                                        <span x-text="m.producto"></span>
                                    </p>
                                    <p class="font-mono text-xs text-slate-500" x-text="codigo(m.ubicacion)"></p>
                                </div>
                                <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-bold tabular-nums"
                                      :class="m.cantidad === m.solicitado ? 'bg-emerald-50 text-emerald-700' : (m.cantidad === 0 ? 'bg-rose-50 text-rose-700' : 'bg-amber-50 text-amber-800')"
                                      x-text="m.cantidad + ' de ' + m.solicitado"></span>
                            </li>
                        </template>
                    </ul>
                </section>
            </div>
        </div>
    </div>
</x-layouts.app>
