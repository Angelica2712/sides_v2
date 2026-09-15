@php
    $datos = [
        'renglones' => $renglones,
        'urls' => [
            'escanear' => route('packing.escanear', $pedido->id),
            'ajustar' => route('packing.ajustar', $pedido->id),
            'clave' => route('packing.clave', $pedido->id),
            'lote' => route('packing.lote', $pedido->id),
        ],
        'claveParaAjustar' => (bool) $cfg?->activarValPacking,
        'separadorCestas' => (bool) $cfg?->activar_separador_automatico,
    ];
@endphp

<x-layouts.app :titulo="'Packing #'.$pedido->id">
    <div class="mx-auto max-w-5xl space-y-4" x-data="packingPedido(@js($datos))">

        {{-- Encabezado del pedido --}}
        <section class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 sm:p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <a href="{{ route('packing.index') }}" class="text-sm font-semibold text-primary hover:underline">← Volver a la lista</a>
                    <div class="mt-1 flex flex-wrap items-center gap-2">
                        <h2 class="text-2xl font-black text-slate-900 tabular-nums">Pedido #{{ $pedido->id }}</h2>
                        <span class="rounded-lg bg-primary px-2.5 py-1 text-sm font-bold text-white">Recipiente {{ $pedido->recipiente }}</span>
                    </div>
                    <p class="mt-1 truncate text-sm text-slate-600">{{ $pedido->ruta ?: 'Sin ruta' }} · {{ $pedido->codcli }} · {{ $pedido->nomcli }}</p>
                    <p class="text-xs text-slate-500">Picking de {{ $pedido->despachador ?: '—' }}</p>
                    @if ($pedido->observacion || $pedido->codtransp)
                        <p class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900 ring-1 ring-amber-200">
                            @if ($pedido->observacion) <span class="font-semibold">Observación:</span> {{ $pedido->observacion }} @endif
                            @if ($pedido->codtransp) <span class="font-semibold">Transporte:</span> {{ $pedido->codtransp }} @endif
                        </p>
                    @endif
                </div>
                <div class="flex gap-2">
                    <button type="button" @click="$refs.liberar.showModal()" class="rounded-xl bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">Liberar</button>
                    <button type="button" @click="abrirTerminar()"
                            :class="listo ? 'bg-emerald-600 text-white hover:bg-emerald-700' : 'bg-white text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50'"
                            class="rounded-xl px-4 py-2.5 text-sm font-bold">Terminar packing</button>
                </div>
            </div>

            <div class="mt-4 grid grid-cols-3 gap-2 text-center">
                <div class="rounded-xl bg-slate-50 px-2 py-2.5">
                    <div class="text-[11px] font-bold uppercase tracking-wide text-slate-500">Productos</div>
                    <div class="text-xl font-black tabular-nums text-slate-900"><span x-text="completos.length"></span>/<span x-text="renglones.length"></span></div>
                </div>
                <div class="rounded-xl bg-slate-50 px-2 py-2.5">
                    <div class="text-[11px] font-bold uppercase tracking-wide text-slate-500">Unidades verificadas</div>
                    <div class="text-xl font-black tabular-nums text-slate-900"><span x-text="unidadesVerificadas"></span>/<span x-text="unidadesADespachar"></span></div>
                </div>
                <div class="rounded-xl bg-slate-50 px-2 py-2.5">
                    <div class="text-[11px] font-bold uppercase tracking-wide text-slate-500">Faltan</div>
                    <div class="text-xl font-black tabular-nums text-slate-900" x-text="unidadesADespachar - unidadesVerificadas"></div>
                </div>
            </div>
            <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-200" role="progressbar" aria-label="Avance del packing"
                 :aria-valuenow="progreso" aria-valuemin="0" aria-valuemax="100">
                <div class="h-full rounded-full bg-emerald-500 transition-[width] duration-300" :style="'width: ' + progreso + '%'"></div>
            </div>
        </section>

        {{-- Lector --}}
        <div>
            <form @submit.prevent="leer()" class="flex gap-2">
                <div class="flex w-24 shrink-0 flex-col">
                    <label for="unidades" class="sr-only">Unidades a verificar con la próxima lectura</label>
                    <input x-model.number="unidades" id="unidades" type="number" min="1" inputmode="numeric" title="Unidades"
                           @keydown.enter.prevent="$refs.lectura.focus()"
                           class="h-full w-full rounded-2xl border-slate-300 bg-white px-2 text-center text-lg font-black tabular-nums shadow-sm focus:border-primary focus:ring-primary">
                </div>
                <label for="lectura" class="sr-only">Código de barras del producto</label>
                <input x-ref="lectura" x-model="lectura" id="lectura" type="text" :inputmode="teclado ? 'text' : 'none'" autocomplete="off"
                       placeholder="Escanea el código de barras"
                       class="w-full rounded-2xl border-slate-300 bg-white px-4 py-3.5 text-lg font-semibold shadow-sm focus:border-primary focus:ring-primary">
                <button type="submit" class="rounded-2xl bg-slate-800 px-5 text-sm font-bold text-white hover:bg-slate-900">Verificar</button>
            </form>
            <p class="mt-1.5 text-xs text-slate-500">
                A la izquierda van las <span class="font-semibold">unidades</span> que se verifican con cada lectura (vuelve a 1 después de cada una).
                También puedes escribir <span class="font-mono font-semibold">3*código</span> directamente en el lector.
            </p>
            <x-lector-opciones class="mt-2" />
        </div>

        <p x-show="mensaje" x-cloak aria-live="polite"
           :class="tipoMensaje === 'exito' ? 'bg-emerald-50 text-emerald-800 ring-emerald-200' : 'bg-rose-50 text-rose-800 ring-rose-200'"
           class="rounded-xl px-4 py-3 text-sm font-semibold ring-1" x-text="mensaje"></p>

        <section x-show="listo" x-cloak class="rounded-2xl bg-emerald-50 p-5 text-center ring-1 ring-emerald-200">
            <h3 class="text-lg font-extrabold text-emerald-900">Todo verificado</h3>
            <p class="mt-1 text-sm text-emerald-800">Indica los bultos y termina el packing para enviar el pedido a facturar.</p>
            <button type="button" @click="abrirTerminar()" class="mt-3 rounded-xl bg-emerald-600 px-6 py-3 font-bold text-white hover:bg-emerald-700">Terminar packing</button>
        </section>

        {{-- Renglones: pendientes primero, verificados al final --}}
        <ul class="space-y-2.5">
            <template x-for="renglon in ordenados" :key="renglon.item">
                <li class="rounded-2xl border-l-4 bg-white p-4 shadow-sm ring-1 ring-slate-200 transition"
                    :class="{
                        'border-l-emerald-500': estadoDe(renglon) === 'completo',
                        'border-l-amber-400': estadoDe(renglon) === 'parcial',
                        'border-l-slate-300': estadoDe(renglon) === 'pendiente',
                        'border-l-slate-200 opacity-70': estadoDe(renglon) === 'sin-despacho',
                        'ring-2 ring-primary': ultimoItem === renglon.item,
                    }">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <p class="font-bold text-slate-900" x-text="renglon.desprod"></p>
                            <p class="text-xs text-slate-500">
                                <span x-text="renglon.codprod"></span> · <span class="font-mono" x-text="renglon.barra"></span>
                                <span x-show="renglon.marca"> · <span x-text="renglon.marca"></span></span>
                            </p>
                            <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                                <span x-show="renglon.refrigerado" class="rounded-full bg-sky-100 px-2 py-0.5 text-[11px] font-bold text-sky-800">Refrigerado</span>
                                <span x-show="renglon.psicotropico" class="rounded-full bg-purple-100 px-2 py-0.5 text-[11px] font-bold text-purple-800">Psicotrópico</span>
                                <span x-show="renglon.lote" class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-700">
                                    Lote <span x-text="renglon.lote"></span><span x-show="renglon.vence"> · vence <span x-text="renglon.vence"></span></span>
                                </span>
                            </div>

                            {{-- Cambio de lote (renglones marcados con alerta de lote en picking) --}}
                            <form x-show="renglon.alertalote && renglon.lotes.length > 1" @submit.prevent="cambiarLote(renglon, $event.target.lote.value)"
                                  class="mt-2 flex flex-wrap items-center gap-2 rounded-xl bg-amber-50 p-2 ring-1 ring-amber-200">
                                <label :for="'lote-' + renglon.item" class="text-xs font-bold text-amber-900">Lote</label>
                                <select name="lote" :id="'lote-' + renglon.item" class="rounded-lg border-amber-300 py-1 text-sm focus:border-amber-500 focus:ring-amber-500">
                                    <template x-for="opcion in renglon.lotes" :key="opcion.valor">
                                        <option :value="opcion.valor" :selected="opcion.lote === renglon.lote"
                                                x-text="opcion.lote + ' · vence ' + opcion.vence + ' · ' + opcion.cantidad + ' und'"></option>
                                    </template>
                                </select>
                                <button type="submit" class="rounded-lg bg-amber-600 px-3 py-1 text-xs font-bold text-white hover:bg-amber-700">Cambiar lote</button>
                            </form>
                        </div>

                        <div class="flex shrink-0 items-center gap-3">
                            <div class="text-right">
                                <p class="text-2xl font-black tabular-nums"
                                   :class="{ 'text-emerald-600': estadoDe(renglon) === 'completo', 'text-amber-600': estadoDe(renglon) === 'parcial', 'text-slate-900': estadoDe(renglon) === 'pendiente', 'text-slate-400': estadoDe(renglon) === 'sin-despacho' }">
                                    <span x-text="Math.min(renglon.chequeado, Math.max(renglon.cantdesp, 0))"></span>/<span x-text="Math.max(renglon.cantdesp, 0)"></span>
                                </p>
                                <p class="text-[11px] text-slate-500">
                                    <span x-show="renglon.cantdesp !== renglon.cantidad">pedido <span x-text="renglon.cantidad"></span> · </span>
                                    <span x-text="{ completo: 'verificado', parcial: 'verificando', pendiente: 'por verificar', 'sin-despacho': 'no se despacha' }[estadoDe(renglon)]"></span>
                                </p>
                            </div>
                            <button type="button" @click="abrirAjuste(renglon)" class="rounded-xl bg-white px-3 py-2 text-xs font-semibold text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">Ajustar</button>
                        </div>
                    </div>
                </li>
            </template>
        </ul>

        {{-- Código que no corresponde: exige clave de supervisor para continuar (como el legacy) --}}
        <dialog x-ref="bloqueo" @cancel.prevent class="m-auto w-[min(24rem,calc(100%-2rem))] rounded-2xl p-0 shadow-xl backdrop:bg-slate-900/70">
            <form @submit.prevent="desbloquear()" class="space-y-4 p-6">
                <h3 class="text-lg font-extrabold text-rose-700" x-text="motivoBloqueo"></h3>
                <p class="text-sm text-slate-600">Aparta ese producto. Para seguir verificando, un supervisor debe escribir su clave.</p>
                <label for="clave-bloqueo" class="sr-only">Clave de supervisor</label>
                <input x-ref="campoBloqueo" x-model="clave" id="clave-bloqueo" type="password" autocomplete="off"
                       class="block w-full rounded-xl border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-primary focus:ring-primary">
                <p x-show="errorClave" x-text="errorClave" class="text-sm font-semibold text-rose-700"></p>
                <div class="flex justify-end">
                    <button type="submit" :disabled="enviando" class="rounded-xl bg-primary px-4 py-2 text-sm font-bold text-white hover:bg-primary-dark disabled:opacity-60">Continuar</button>
                </div>
            </form>
        </dialog>

        {{-- Ajustar cantidad --}}
        <dialog x-ref="ajuste" class="m-auto w-[min(26rem,calc(100%-2rem))] rounded-2xl p-0 shadow-xl backdrop:bg-slate-900/60">
            <form @submit.prevent="guardarAjuste()" class="space-y-4 p-6" x-show="ajuste">
                <div>
                    <h3 class="text-lg font-extrabold text-slate-900">Ajustar cantidad</h3>
                    <p class="mt-1 text-sm text-slate-600" x-text="ajuste?.desprod"></p>
                </div>
                <div>
                    <label for="cantidad-ajuste" class="block text-sm font-semibold text-slate-700">Unidades que se despachan (máximo <span x-text="ajuste?.cantidad"></span>)</label>
                    <input x-ref="campoAjuste" x-model.number="cantidadAjuste" id="cantidad-ajuste" type="number" min="0" :max="ajuste?.cantidad" inputmode="numeric"
                           class="mt-1.5 block w-32 rounded-xl border-slate-300 text-center text-2xl font-black tabular-nums focus:border-primary focus:ring-primary">
                    <p class="mt-1 text-xs text-slate-500">La cantidad queda como verificada.</p>
                </div>
                <div x-show="claveParaAjustar">
                    <label for="clave-ajuste" class="block text-sm font-semibold text-slate-700">Clave de supervisor</label>
                    <input x-model="clave" id="clave-ajuste" type="password" autocomplete="off"
                           class="mt-1.5 block w-full rounded-xl border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-primary focus:ring-primary">
                </div>
                <p x-show="errorClave" x-text="errorClave" class="text-sm font-semibold text-rose-700"></p>
                <div class="flex justify-end gap-2">
                    <button type="button" @click="$refs.ajuste.close()" class="rounded-xl px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100">Cancelar</button>
                    <button type="submit" :disabled="enviando" class="rounded-xl bg-primary px-4 py-2 text-sm font-bold text-white hover:bg-primary-dark disabled:opacity-60">Guardar</button>
                </div>
            </form>
        </dialog>

        {{-- Terminar --}}
        <dialog x-ref="terminar" class="m-auto w-[min(28rem,calc(100%-2rem))] rounded-2xl p-0 shadow-xl backdrop:bg-slate-900/60">
            <form method="POST" action="{{ route('packing.terminar', $pedido->id) }}" class="space-y-4 p-6">
                @csrf
                <h3 class="text-lg font-extrabold text-slate-900">Terminar el packing del pedido #{{ $pedido->id }}</h3>
                <p x-show="!listo" class="rounded-lg bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-900">
                    Todavía faltan <span x-text="unidadesADespachar - unidadesVerificadas"></span> unidades por verificar.
                </p>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label for="cantBultos" class="block text-sm font-semibold text-slate-700">Total de bultos</label>
                        <input id="cantBultos" name="cantBultos" type="text" required maxlength="100" value="{{ $pedido->cantBultos ?: '1' }}"
                               class="mt-1.5 block w-full rounded-xl border-slate-300 px-3.5 py-2.5 text-lg font-bold shadow-sm focus:border-primary focus:ring-primary">
                    </div>
                    <div>
                        <label for="cestas" class="block text-sm font-semibold text-slate-700">Cestas de salida</label>
                        <input id="cestas" name="cestas" type="text" maxlength="500" value="{{ $pedido->num_cesta_ped }}" @input="separarCestas($event)"
                               class="mt-1.5 block w-full rounded-xl border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-primary focus:ring-primary">
                    </div>
                    <div>
                        <label for="despachador" class="block text-sm font-semibold text-slate-700">Responsable de picking</label>
                        <input id="despachador" name="despachador" type="text" maxlength="100" value="{{ $pedido->despachador }}"
                               class="mt-1.5 block w-full rounded-xl border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-primary focus:ring-primary">
                    </div>
                    <div>
                        <label for="embalador" class="block text-sm font-semibold text-slate-700">Responsable de packing</label>
                        <input id="embalador" name="embalador" type="text" maxlength="100" value="{{ auth()->user()->name }}"
                               class="mt-1.5 block w-full rounded-xl border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-primary focus:ring-primary">
                    </div>
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" @click="$refs.terminar.close()" class="rounded-xl px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100">Cancelar</button>
                    <button type="submit" class="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-bold text-white hover:bg-emerald-700">Enviar a facturar</button>
                </div>
            </form>
        </dialog>

        <dialog x-ref="liberar" class="m-auto w-[min(26rem,calc(100%-2rem))] rounded-2xl p-0 shadow-xl backdrop:bg-slate-900/60">
            <form method="POST" action="{{ route('packing.liberar', $pedido->id) }}" class="space-y-4 p-6">
                @csrf
                <h3 class="text-lg font-extrabold text-slate-900">¿Liberar el pedido #{{ $pedido->id }}?</h3>
                <p class="text-sm text-slate-600">Queda disponible para otro empacador. Lo que ya verificaste se conserva.</p>
                <div class="flex justify-end gap-2">
                    <button type="button" @click="$refs.liberar.close()" class="rounded-xl px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100">Cancelar</button>
                    <button type="submit" class="rounded-xl bg-primary px-4 py-2 text-sm font-bold text-white hover:bg-primary-dark">Liberar pedido</button>
                </div>
            </form>
        </dialog>
    </div>
</x-layouts.app>
