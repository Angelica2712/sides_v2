@php
    $datos = [
        'renglones' => $renglones,
        'urls' => [
            'cantidad' => route('picking.cantidad', $pedido->id),
            'alerta' => route('picking.alerta', $pedido->id),
        ],
        'requiereClave' => (bool) $cfg?->activarValPicking,
    ];
    $mostrarExistencia = (bool) $cfg?->mostrarExiRealPick;
    $mostrarDeposito = (bool) $cfg?->mostrarDepPiking;
@endphp

<x-layouts.app :titulo="'Picking #'.$pedido->id">
    <div class="mx-auto max-w-5xl space-y-4" x-data="pickingPedido(@js($datos))">

        {{-- Encabezado del pedido --}}
        <section class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 sm:p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <a href="{{ route('picking.index') }}" class="text-sm font-semibold text-primary hover:underline">← Volver a la lista</a>
                    <div class="mt-1 flex flex-wrap items-center gap-2">
                        <h2 class="text-2xl font-black text-slate-900 tabular-nums">Pedido #{{ $pedido->id }}</h2>
                        <span class="rounded-lg bg-primary px-2.5 py-1 text-sm font-bold text-white">Recipiente {{ $pedido->recipiente }}</span>
                    </div>
                    <p class="mt-1 truncate text-sm text-slate-600">{{ $pedido->ruta ?: 'Sin ruta' }} · {{ $pedido->codcli }} · {{ $pedido->nomcli }}</p>
                    @if ($pedido->observacion || $pedido->codtransp)
                        <p class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900 ring-1 ring-amber-200">
                            @if ($pedido->observacion) <span class="font-semibold">Observación:</span> {{ $pedido->observacion }} @endif
                            @if ($pedido->codtransp) <span class="font-semibold">Transporte:</span> {{ $pedido->codtransp }} @endif
                        </p>
                    @endif
                </div>
                <div class="flex gap-2">
                    <button type="button" @click="$refs.liberar.showModal()" class="rounded-xl bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">Liberar</button>
                    <button type="button" @click="$refs.terminar.showModal()"
                            :class="pendientes.length === 0 ? 'bg-emerald-600 hover:bg-emerald-700 text-white' : 'bg-white text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50'"
                            class="rounded-xl px-4 py-2.5 text-sm font-bold">Terminar picking</button>
                </div>
            </div>

            {{-- Avance --}}
            <div class="mt-4 grid grid-cols-3 gap-2 text-center">
                <div class="rounded-xl bg-slate-50 px-2 py-2.5">
                    <div class="text-[11px] font-bold uppercase tracking-wide text-slate-500">Productos</div>
                    <div class="text-xl font-black tabular-nums text-slate-900"><span x-text="revisados.length"></span>/<span x-text="renglones.length"></span></div>
                </div>
                <div class="rounded-xl bg-slate-50 px-2 py-2.5">
                    <div class="text-[11px] font-bold uppercase tracking-wide text-slate-500">Unidades</div>
                    <div class="text-xl font-black tabular-nums text-slate-900"><span x-text="unidadesDespachadas"></span>/<span x-text="unidadesSolicitadas"></span></div>
                </div>
                <div class="rounded-xl bg-slate-50 px-2 py-2.5">
                    <div class="text-[11px] font-bold uppercase tracking-wide text-slate-500">Faltan</div>
                    <div class="text-xl font-black tabular-nums text-slate-900" x-text="unidadesFaltantes"></div>
                </div>
            </div>
            <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-200" role="progressbar" aria-label="Avance del picking"
                 :aria-valuenow="progreso" aria-valuemin="0" aria-valuemax="100">
                <div class="h-full rounded-full bg-emerald-500 transition-[width] duration-300" :style="'width: ' + progreso + '%'"></div>
            </div>
        </section>

        {{-- Lector --}}
        <div x-show="actual && !seleccionado" class="space-y-2">
            <form @submit.prevent="leer()" class="flex gap-2">
                <label for="lectura" class="sr-only">Código de barras del producto</label>
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

        {{-- Producto actual --}}
        <template x-if="actual">
            <section class="overflow-hidden rounded-2xl bg-white shadow-md ring-2 ring-primary" aria-labelledby="producto-actual">
                <div class="flex flex-wrap items-center justify-between gap-2 bg-primary px-5 py-3 text-white">
                    <span id="producto-actual" class="text-xs font-bold uppercase tracking-widest text-white/80">Siguiente producto</span>
                    <span class="text-xs font-semibold text-white/80">Ubicación</span>
                </div>
                <div class="grid gap-4 p-5 sm:grid-cols-[1fr_auto]">
                    <div class="min-w-0 space-y-1.5">
                        <p class="text-xl font-extrabold leading-snug text-slate-900" x-text="actual.desprod"></p>
                        <p class="text-sm text-slate-600">
                            Código <span class="font-bold text-slate-800" x-text="actual.codprod"></span>
                            · Barra <span class="font-mono font-bold text-slate-800" x-text="actual.barra"></span>
                        </p>
                        <p class="text-sm text-slate-600" x-show="actual.lote">
                            Lote <span class="font-bold text-slate-800" x-text="actual.lote"></span>
                            <span x-show="actual.vence">· vence <span class="font-bold text-slate-800" x-text="actual.vence"></span></span>
                        </p>
                        <p class="text-sm text-slate-600" x-show="actual.marca">Marca <span class="font-semibold text-slate-800" x-text="actual.marca"></span></p>
                        <div class="flex flex-wrap gap-2 pt-1">
                            <span x-show="actual.refrigerado" class="rounded-full bg-sky-100 px-2.5 py-1 text-xs font-bold text-sky-800">Refrigerado</span>
                            @if ($mostrarExistencia)
                                <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700">Existencia <span x-text="actual.existencia"></span></span>
                            @endif
                            @if ($mostrarDeposito)
                                <span x-show="actual.deposito" class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700">Depósito <span x-text="actual.deposito"></span></span>
                            @endif
                            <label x-show="actual.variosLotes" class="flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-bold text-amber-800 ring-1 ring-amber-200">
                                <input type="checkbox" :checked="actual.alertalote" @change="alternarAlerta(actual)" class="rounded border-amber-300 text-amber-600 focus:ring-amber-500">
                                Alerta de lote
                            </label>
                        </div>
                    </div>
                    <div class="flex flex-row items-center justify-between gap-4 sm:flex-col sm:items-end sm:justify-start">
                        {{-- Ubicaciones tipo "A01/Existencias/Picking/Area A/A01PICKPAE05A" (mastranto): el código final va en grande. --}}
                        <div class="text-right">
                            <p class="font-black leading-none tracking-tight text-slate-900 text-4xl sm:text-5xl" x-text="(actual.ubicacion || '').split('/').pop() || 'Sin ubicación'"></p>
                            <p x-show="actual.ubicacion.includes('/')" class="mt-1 text-xs text-slate-400" x-text="actual.ubicacion"></p>
                        </div>
                        <p class="text-right text-sm text-slate-500">Solicitado <span class="block text-3xl font-black text-slate-900 tabular-nums" x-text="actual.cantidad"></span></p>
                    </div>
                </div>

                {{-- Confirmar cantidad --}}
                <div class="border-t border-slate-100 bg-slate-50 p-5">
                    <div x-show="!seleccionado" class="flex flex-wrap items-center justify-between gap-3">
                        <p class="text-sm text-slate-600">Escanea el producto para confirmar la cantidad.</p>
                        <button type="button" @click="seleccionar(true)" class="rounded-xl bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 ring-1 ring-slate-300 hover:bg-slate-100">
                            Confirmar sin escanear
                        </button>
                    </div>

                    <form x-show="seleccionado" x-cloak @submit.prevent="guardar()" class="space-y-3">
                        <label for="cantidad" class="block text-sm font-bold text-slate-700">Cantidad que despachas</label>
                        <div class="flex flex-wrap items-stretch gap-2">
                            <button type="button" @click="sumar(-1)" aria-label="Restar una unidad" class="flex w-16 items-center justify-center rounded-xl bg-white text-3xl font-black text-slate-700 ring-1 ring-slate-300 hover:bg-slate-100">−</button>
                            <input x-ref="cantidad" x-model.number="cantidad" id="cantidad" type="number" min="0" :max="actual.cantidad" inputmode="numeric"
                                   class="w-28 rounded-xl border-slate-300 text-center text-3xl font-black tabular-nums focus:border-primary focus:ring-primary">
                            <button type="button" @click="sumar(1)" aria-label="Sumar una unidad" class="flex w-16 items-center justify-center rounded-xl bg-white text-3xl font-black text-slate-700 ring-1 ring-slate-300 hover:bg-slate-100">+</button>
                            <button type="button" @click="cantidad = 0" class="rounded-xl bg-white px-4 text-sm font-bold text-rose-700 ring-1 ring-rose-200 hover:bg-rose-50">Marcar 0</button>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button x-ref="confirmar" type="submit" :disabled="guardando"
                                    class="flex-1 rounded-xl bg-emerald-600 px-5 py-3 text-base font-bold text-white hover:bg-emerald-700 disabled:opacity-60 sm:flex-none">
                                <span x-text="guardando ? 'Guardando…' : 'Guardar cantidad'"></span>
                            </button>
                            <button type="button" @click="cancelar()" class="rounded-xl px-4 py-3 text-sm font-semibold text-slate-600 hover:bg-slate-200">Cancelar</button>
                        </div>
                    </form>
                </div>
            </section>
        </template>

        {{-- Todo revisado --}}
        <section x-show="!actual" x-cloak class="rounded-2xl bg-emerald-50 p-6 text-center ring-1 ring-emerald-200">
            <h3 class="text-lg font-extrabold text-emerald-900">Revisaste todos los productos</h3>
            <p class="mt-1 text-sm text-emerald-800">Termina el picking para enviar el pedido a la siguiente etapa.</p>
            <button type="button" @click="$refs.terminar.showModal()" class="mt-4 rounded-xl bg-emerald-600 px-6 py-3 font-bold text-white hover:bg-emerald-700">Terminar picking</button>
        </section>

        {{-- Siguientes y revisados --}}
        <div class="grid gap-4 lg:grid-cols-2">
            <section class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                <h3 class="mb-2 text-sm font-bold uppercase tracking-wide text-slate-500">Siguientes (<span x-text="Math.max(pendientes.length - 1, 0)"></span>)</h3>
                <p x-show="pendientes.length <= 1" class="py-3 text-sm text-slate-400">No hay más productos pendientes.</p>
                <ul class="divide-y divide-slate-100">
                    <template x-for="renglon in pendientes.slice(1)" :key="renglon.item">
                        <li class="flex items-center justify-between gap-3 py-2">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-slate-800" x-text="renglon.desprod"></p>
                                <p class="text-xs text-slate-500" x-text="renglon.codprod + ' · ' + renglon.barra"></p>
                            </div>
                            <div class="shrink-0 text-right">
                                <p class="text-sm font-black text-slate-900" x-text="(renglon.ubicacion || '').split('/').pop()"></p>
                                <p class="text-xs text-slate-500">x<span x-text="renglon.cantidad"></span></p>
                            </div>
                        </li>
                    </template>
                </ul>
            </section>

            <section class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                <h3 class="mb-2 text-sm font-bold uppercase tracking-wide text-slate-500">Revisados (<span x-text="revisados.length"></span>)</h3>
                <p x-show="revisados.length === 0" class="py-3 text-sm text-slate-400">Todavía no revisaste productos.</p>
                <ul class="divide-y divide-slate-100">
                    <template x-for="renglon in revisados" :key="renglon.item">
                        <li class="flex items-center justify-between gap-3 py-2">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-slate-800" x-text="renglon.desprod"></p>
                                <p class="text-xs text-slate-500" x-text="renglon.ubicacion + ' · ' + renglon.codprod"></p>
                            </div>
                            <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-bold tabular-nums"
                                  :class="renglon.cantdesp === renglon.cantidad ? 'bg-emerald-50 text-emerald-700' : (renglon.cantdesp === 0 ? 'bg-rose-50 text-rose-700' : 'bg-amber-50 text-amber-800')"
                                  x-text="renglon.cantdesp + ' de ' + renglon.cantidad"></span>
                        </li>
                    </template>
                </ul>
            </section>
        </div>

        {{-- Clave de supervisor (sides_cfg.activarValPicking) --}}
        <dialog x-ref="clave" class="m-auto w-[min(24rem,calc(100%-2rem))] rounded-2xl p-0 shadow-xl backdrop:bg-slate-900/60">
            <form @submit.prevent="confirmarClave()" class="space-y-4 p-6">
                <h3 class="text-lg font-extrabold text-slate-900">Autorización</h3>
                <p class="text-sm text-slate-600">Para confirmar sin escanear, un supervisor debe escribir su clave.</p>
                <label for="clave-supervisor" class="sr-only">Clave de supervisor</label>
                <input x-ref="campoClave" x-model="clave" id="clave-supervisor" type="password" autocomplete="off"
                       class="block w-full rounded-xl border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-primary focus:ring-primary">
                <div class="flex justify-end gap-2">
                    <button type="button" @click="$refs.clave.close(); clave = ''" class="rounded-xl px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100">Cancelar</button>
                    <button type="submit" class="rounded-xl bg-primary px-4 py-2 text-sm font-bold text-white hover:bg-primary-dark">Continuar</button>
                </div>
            </form>
        </dialog>

        <dialog x-ref="terminar" class="m-auto w-[min(26rem,calc(100%-2rem))] rounded-2xl p-0 shadow-xl backdrop:bg-slate-900/60">
            <form method="POST" action="{{ route('picking.terminar', $pedido->id) }}" class="space-y-4 p-6">
                @csrf
                <h3 class="text-lg font-extrabold text-slate-900">¿Terminar el picking del pedido #{{ $pedido->id }}?</h3>
                <p x-show="pendientes.length > 0" class="rounded-lg bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-900">
                    Todavía quedan <span x-text="pendientes.length"></span> productos sin revisar.
                </p>
                <p x-show="pendientes.length === 0" class="text-sm text-slate-600">
                    {{ ($cfg?->activarPacking ?? true) ? 'El pedido pasará a packing.' : 'El pedido pasará a facturación.' }}
                </p>
                <div class="flex justify-end gap-2">
                    <button type="button" @click="$refs.terminar.close()" class="rounded-xl px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100">Cancelar</button>
                    <button type="submit" class="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-bold text-white hover:bg-emerald-700">Terminar</button>
                </div>
            </form>
        </dialog>

        <dialog x-ref="liberar" class="m-auto w-[min(26rem,calc(100%-2rem))] rounded-2xl p-0 shadow-xl backdrop:bg-slate-900/60">
            <form method="POST" action="{{ route('picking.liberar', $pedido->id) }}" class="space-y-4 p-6">
                @csrf
                <h3 class="text-lg font-extrabold text-slate-900">¿Liberar el pedido #{{ $pedido->id }}?</h3>
                <p class="text-sm text-slate-600">Queda disponible para otro operario. Lo que ya revisaste se conserva y se registra el tiempo parcial.</p>
                <div class="flex justify-end gap-2">
                    <button type="button" @click="$refs.liberar.close()" class="rounded-xl px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100">Cancelar</button>
                    <button type="submit" class="rounded-xl bg-primary px-4 py-2 text-sm font-bold text-white hover:bg-primary-dark">Liberar pedido</button>
                </div>
            </form>
        </dialog>
    </div>
</x-layouts.app>
