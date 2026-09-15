@php
    $datos = [
        'productos' => $productos,
        'urls' => ['cantidad' => route('batch.cantidad', $lote->id)],
    ];
@endphp

<x-layouts.app :titulo="'Lote #'.$lote->id">
    <div class="mx-auto max-w-5xl space-y-4" x-data="batchPicking(@js($datos))">

        <section class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 sm:p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <a href="{{ route('picking.index') }}" class="text-sm font-semibold text-primary hover:underline">← Volver a picking</a>
                    <div class="mt-1 flex flex-wrap items-center gap-2">
                        <h2 class="text-2xl font-black text-slate-900">Lote #{{ $lote->id }}</h2>
                        <span class="rounded-lg bg-primary px-2.5 py-1 text-sm font-bold text-white">Recipiente {{ $recipiente }}</span>
                    </div>
                    <details class="mt-1 text-sm text-slate-600">
                        <summary class="cursor-pointer font-semibold">{{ $pedidos->count() }} pedidos en el lote</summary>
                        <ul class="mt-1 space-y-0.5 text-xs">
                            @foreach ($pedidos as $pedido)
                                <li><span class="font-bold tabular-nums">#{{ $pedido->id }}</span> · {{ $pedido->nomcli }}</li>
                            @endforeach
                        </ul>
                    </details>
                </div>
                <button type="button" @click="$refs.terminar.showModal()"
                        :class="listo ? 'bg-emerald-600 text-white hover:bg-emerald-700' : 'bg-white text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50'"
                        class="rounded-xl px-4 py-2.5 text-sm font-bold">Terminar picking del lote</button>
            </div>

            <div class="mt-4 grid grid-cols-3 gap-2 text-center">
                <div class="rounded-xl bg-slate-50 px-2 py-2.5">
                    <div class="text-[11px] font-bold uppercase tracking-wide text-slate-500">Productos</div>
                    <div class="text-xl font-black tabular-nums text-slate-900"><span x-text="productos.length - pendientes.length"></span>/<span x-text="productos.length"></span></div>
                </div>
                <div class="rounded-xl bg-slate-50 px-2 py-2.5">
                    <div class="text-[11px] font-bold uppercase tracking-wide text-slate-500">Unidades</div>
                    <div class="text-xl font-black tabular-nums text-slate-900"><span x-text="unidadesPickeadas"></span>/<span x-text="unidadesRequeridas"></span></div>
                </div>
                <div class="rounded-xl bg-slate-50 px-2 py-2.5">
                    <div class="text-[11px] font-bold uppercase tracking-wide text-slate-500">Por marcar</div>
                    <div class="text-xl font-black tabular-nums text-slate-900" x-text="pendientes.length"></div>
                </div>
            </div>
            <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-200" role="progressbar" aria-label="Avance del picking del lote"
                 :aria-valuenow="progreso" aria-valuemin="0" aria-valuemax="100">
                <div class="h-full rounded-full bg-emerald-500 transition-[width] duration-300" :style="'width: ' + progreso + '%'"></div>
            </div>
        </section>

        <div class="space-y-2">
            <form @submit.prevent="leer()" class="flex gap-2">
                <label for="lectura" class="sr-only">Código de barras o código del producto</label>
                <input x-ref="lectura" x-model="lectura" id="lectura" type="text" :inputmode="teclado ? 'text' : 'none'" autocomplete="off"
                       placeholder="Escanea el código de barras del producto"
                       class="w-full rounded-2xl border-slate-300 bg-white px-4 py-3.5 text-lg font-semibold shadow-sm focus:border-primary focus:ring-primary">
                <button type="submit" class="rounded-2xl bg-slate-800 px-5 text-sm font-bold text-white hover:bg-slate-900">Buscar</button>
            </form>
            <x-lector-opciones />
        </div>

        <p x-show="mensaje" x-cloak aria-live="polite"
           :class="tipoMensaje === 'exito' ? 'bg-emerald-50 text-emerald-800 ring-emerald-200' : 'bg-rose-50 text-rose-800 ring-rose-200'"
           class="rounded-xl px-4 py-3 text-sm font-semibold ring-1" x-text="mensaje"></p>

        <p class="text-xs text-slate-500">
            Las cantidades son la suma de todos los pedidos del lote. Si falta mercancía, se completan primero los pedidos más antiguos.
        </p>

        <ul class="space-y-2.5">
            <template x-for="producto in ordenados" :key="producto.clave">
                <li class="rounded-2xl border-l-4 bg-white p-4 shadow-sm ring-1 ring-slate-200"
                    :class="!producto.tocado ? 'border-l-slate-300' : (producto.pickeado >= producto.requerido ? 'border-l-emerald-500' : 'border-l-amber-400')">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            {{-- Ubicaciones tipo "A01/Existencias/Picking/Area A/A01PICKPAE05A": el código final va en grande. --}}
                            <p class="text-2xl font-black tracking-tight text-slate-900" x-text="(producto.ubicacion || '').split('/').pop() || 'Sin ubicación'"></p>
                            <p x-show="producto.ubicacion.includes('/')" class="text-[11px] text-slate-400" x-text="producto.ubicacion"></p>
                            <p class="font-bold text-slate-900" x-text="producto.desprod"></p>
                            <p class="text-xs text-slate-500">
                                <span x-text="producto.codprod"></span> · <span class="font-mono" x-text="producto.barra"></span>
                                <span x-show="producto.marca"> · <span x-text="producto.marca"></span></span>
                            </p>
                            <div class="mt-1.5 flex flex-wrap gap-1.5">
                                <span x-show="producto.lote" class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-700">
                                    Lote <span x-text="producto.lote"></span><span x-show="producto.vence"> · vence <span x-text="producto.vence"></span></span>
                                </span>
                                <span x-show="producto.refrigerado" class="rounded-full bg-sky-100 px-2 py-0.5 text-[11px] font-bold text-sky-800">Refrigerado</span>
                                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-700">
                                    <span x-text="producto.reparto.length"></span> <span x-text="producto.reparto.length === 1 ? 'pedido' : 'pedidos'"></span>
                                </span>
                            </div>
                        </div>
                        <div class="flex shrink-0 items-center gap-3">
                            <div class="text-right">
                                <p class="text-2xl font-black tabular-nums"
                                   :class="!producto.tocado ? 'text-slate-900' : (producto.pickeado >= producto.requerido ? 'text-emerald-600' : 'text-amber-600')">
                                    <span x-text="producto.tocado ? producto.pickeado : '—'"></span>/<span x-text="producto.requerido"></span>
                                </p>
                                <p class="text-[11px] text-slate-500" x-text="!producto.tocado ? 'por marcar' : (producto.pickeado >= producto.requerido ? 'completo' : 'con faltante')"></p>
                            </div>
                            <button type="button" @click="indicarCantidad(producto)" class="rounded-xl bg-white px-3 py-2 text-xs font-semibold text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">Cantidad</button>
                        </div>
                    </div>
                    <details class="mt-2">
                        <summary class="cursor-pointer text-xs font-semibold text-primary">Reparto por pedido</summary>
                        <ul class="mt-1 divide-y divide-slate-100 text-xs">
                            <template x-for="parte in producto.reparto" :key="parte.numped + '-' + parte.item">
                                <li class="flex justify-between gap-3 py-1">
                                    <span class="truncate"><span class="font-bold tabular-nums" x-text="'#' + parte.numped"></span> · <span x-text="parte.nomcli"></span></span>
                                    <span class="shrink-0 tabular-nums"><span x-text="producto.tocado ? parte.asignado : '—'"></span> de <span x-text="parte.cantidad"></span></span>
                                </li>
                            </template>
                        </ul>
                    </details>
                </li>
            </template>
        </ul>

        {{-- Elegir lote de producto cuando el código coincide con varios --}}
        <dialog x-ref="elegirLote" class="m-auto w-[min(26rem,calc(100%-2rem))] rounded-2xl p-0 shadow-xl backdrop:bg-slate-900/60">
            <form @submit.prevent="confirmarLote()" class="space-y-4 p-6">
                <h3 class="text-lg font-extrabold text-slate-900">¿Qué lote tienes en la mano?</h3>
                <p class="text-sm text-slate-600" x-text="opcionesLote[0]?.desprod"></p>
                <fieldset class="space-y-2">
                    <legend class="sr-only">Lotes disponibles</legend>
                    <template x-for="(opcion, indice) in opcionesLote" :key="opcion.clave">
                        <label class="flex cursor-pointer items-center gap-3 rounded-xl p-3 ring-1"
                               :class="loteElegido === opcion.clave ? 'bg-primary-soft ring-primary' : 'ring-slate-200'">
                            <input type="radio" name="lote-elegido" :value="opcion.clave" x-model="loteElegido" class="text-primary focus:ring-primary">
                            <span class="text-sm">
                                <span class="font-bold" x-text="opcion.lote ? 'Lote ' + opcion.lote : 'Sin lote'"></span>
                                <span x-show="opcion.vence" class="text-slate-500"> · vence <span x-text="opcion.vence"></span></span>
                                <span x-show="indice === 0" class="ml-1 rounded-full bg-primary px-2 py-0.5 text-[10px] font-bold text-white">vence primero</span>
                            </span>
                        </label>
                    </template>
                </fieldset>
                <div class="flex justify-end gap-2">
                    <button type="button" @click="$refs.elegirLote.close()" class="rounded-xl px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100">Cancelar</button>
                    <button type="submit" class="rounded-xl bg-primary px-4 py-2 text-sm font-bold text-white hover:bg-primary-dark">Continuar</button>
                </div>
            </form>
        </dialog>

        {{-- ¿Recogiste todo? --}}
        <dialog x-ref="confirmar" class="m-auto w-[min(26rem,calc(100%-2rem))] rounded-2xl p-0 shadow-xl backdrop:bg-slate-900/60">
            <div class="space-y-4 p-6" x-show="actual">
                <div>
                    <p class="text-2xl font-black text-slate-900" x-text="(actual?.ubicacion || '').split('/').pop() || 'Sin ubicación'"></p>
                    <p class="font-bold text-slate-900" x-text="actual?.desprod"></p>
                    <p class="text-xs text-slate-500" x-show="actual?.lote">Lote <span x-text="actual?.lote"></span></p>
                </div>
                <p class="text-slate-700">¿Recogiste las <span class="font-black" x-text="actual?.requerido"></span> unidades para todo el lote?</p>
                <div class="flex flex-wrap justify-end gap-2">
                    <button type="button" @click="indicarCantidad(actual)" class="rounded-xl bg-white px-4 py-2.5 text-sm font-semibold text-amber-800 ring-1 ring-amber-300 hover:bg-amber-50">No, indicar cantidad</button>
                    <button type="button" @click="completo()" :disabled="guardando" class="rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-700 disabled:opacity-60">Sí, completas</button>
                </div>
            </div>
        </dialog>

        {{-- Indicar cantidad --}}
        <dialog x-ref="editar" class="m-auto w-[min(26rem,calc(100%-2rem))] rounded-2xl p-0 shadow-xl backdrop:bg-slate-900/60">
            <form @submit.prevent="guardarEdicion()" class="space-y-4 p-6" x-show="actual">
                <div>
                    <h3 class="text-lg font-extrabold text-slate-900">Cantidad recogida</h3>
                    <p class="text-sm text-slate-600" x-text="actual?.desprod"></p>
                </div>
                <label for="cantidad-lote" class="block text-sm font-semibold text-slate-700">Unidades para todo el lote (máximo <span x-text="actual?.requerido"></span>)</label>
                <div class="flex items-stretch gap-2">
                    <button type="button" @click="sumar(-1)" aria-label="Restar una unidad" class="w-14 rounded-xl bg-white text-2xl font-black text-slate-700 ring-1 ring-slate-300 hover:bg-slate-100">−</button>
                    <input x-ref="campoCantidad" x-model.number="cantidad" id="cantidad-lote" type="number" min="0" :max="actual?.requerido" inputmode="numeric"
                           class="w-28 rounded-xl border-slate-300 text-center text-2xl font-black tabular-nums focus:border-primary focus:ring-primary">
                    <button type="button" @click="sumar(1)" aria-label="Sumar una unidad" class="w-14 rounded-xl bg-white text-2xl font-black text-slate-700 ring-1 ring-slate-300 hover:bg-slate-100">+</button>
                </div>
                <p x-show="errorCantidad" x-text="errorCantidad" class="text-sm font-semibold text-rose-700"></p>
                <div class="flex justify-end gap-2">
                    <button type="button" @click="$refs.editar.close()" class="rounded-xl px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100">Cancelar</button>
                    <button type="submit" :disabled="guardando" class="rounded-xl bg-primary px-4 py-2 text-sm font-bold text-white hover:bg-primary-dark disabled:opacity-60">Guardar</button>
                </div>
            </form>
        </dialog>

        <dialog x-ref="terminar" class="m-auto w-[min(26rem,calc(100%-2rem))] rounded-2xl p-0 shadow-xl backdrop:bg-slate-900/60">
            <form method="POST" action="{{ route('batch.terminar', $lote->id) }}" class="space-y-4 p-6">
                @csrf
                <h3 class="text-lg font-extrabold text-slate-900">¿Terminar el picking del lote #{{ $lote->id }}?</h3>
                <p x-show="!listo" class="rounded-lg bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-900">
                    Todavía faltan <span x-text="pendientes.length"></span> productos por marcar.
                </p>
                <p x-show="listo" class="text-sm text-slate-600">Sus {{ $pedidos->count() }} pedidos pasan juntos a la etapa siguiente. Un pedido sin unidades se anula.</p>
                <div class="flex justify-end gap-2">
                    <button type="button" @click="$refs.terminar.close()" class="rounded-xl px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100">Cancelar</button>
                    <button type="submit" class="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-bold text-white hover:bg-emerald-700">Terminar</button>
                </div>
            </form>
        </dialog>
    </div>
</x-layouts.app>
