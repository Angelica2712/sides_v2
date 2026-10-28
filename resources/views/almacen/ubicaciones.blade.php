@php
    $paso = 'size-11 shrink-0 rounded-xl bg-white text-xl font-bold text-slate-700 ring-1 ring-slate-300 active:bg-slate-100';
    $selector = 'block w-full rounded-lg border-slate-300 py-1.5 text-sm focus:border-primary focus:ring-primary';
    // Sin las flechas del navegador: en el teléfono quitan espacio a los dos dígitos.
    $numero = 'block w-full min-w-0 appearance-none rounded-xl border-slate-300 px-1 py-2 text-center font-mono text-xl font-bold focus:border-primary focus:ring-primary [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none [-moz-appearance:textfield]';
@endphp

<x-layouts.app titulo="Almacén 3D">
    <div class="mx-auto max-w-xl space-y-4" x-data="registroUbicaciones(@js($datos))">
        @include('almacen.partials.pestanas', ['activa' => 'almacen.ubicaciones'])

        <div>
            <h2 class="text-xl font-extrabold text-slate-900">Registrar ubicaciones</h2>
            <p class="text-sm text-slate-500">
                Busca o escanea el producto y escribe dónde está, en el formato
                <span class="font-mono font-bold text-slate-700" x-text="nombreFormato"></span>
                (por ejemplo <span class="font-mono font-bold text-slate-700" x-text="ejemplo"></span>).
                La ubicación de SIDES gana sobre la del ERP.
            </p>
            @if ($editaFormato)
                <button type="button" x-show="!borrador" @click="abrirFormato()"
                        class="mt-2 rounded-lg px-3 py-1.5 text-sm font-semibold text-primary-ink ring-1 ring-primary/30 hover:bg-primary-soft">Cambiar el formato</button>
            @endif
        </div>

        {{-- Formato de la droguería: por defecto el de SIDES; quien la administra arma el suyo. --}}
        <template x-if="borrador">
            <section class="space-y-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-primary/40">
                <div>
                    <h3 class="text-base font-extrabold text-slate-900">Formato de las ubicaciones</h3>
                    <p class="text-xs text-slate-500">
                        Las partes en el orden en que se escriben. Cada una dice qué es en el almacén, así el Almacén 3D y el picking la entienden.
                        El pasillo y el anaquel son obligatorios; sin zona, todo queda en Picking.
                    </p>
                </div>

                <ol class="space-y-2">
                    <template x-for="(parte, i) in borrador.partes" :key="i">
                        <li class="rounded-xl bg-slate-50 p-2 ring-1 ring-slate-200">
                            <div class="flex items-center gap-2">
                                <span class="w-5 shrink-0 text-center text-sm font-black text-slate-500" x-text="i + 1"></span>
                                <input type="text" x-model="parte.nombre" maxlength="20" aria-label="Nombre de la parte" placeholder="Nombre"
                                       class="{{ $selector }} min-w-0 flex-1 font-semibold">
                                <button type="button" @click="moverParte(i, -1)" :disabled="i === 0" aria-label="Subir" class="size-8 shrink-0 rounded-lg text-slate-500 ring-1 ring-slate-300 disabled:opacity-30">↑</button>
                                <button type="button" @click="moverParte(i, 1)" :disabled="i === borrador.partes.length - 1" aria-label="Bajar" class="size-8 shrink-0 rounded-lg text-slate-500 ring-1 ring-slate-300 disabled:opacity-30">↓</button>
                                <button type="button" @click="quitarParte(i)" :disabled="borrador.partes.length <= 2" aria-label="Quitar parte" class="size-8 shrink-0 rounded-lg text-rose-600 ring-1 ring-rose-200 disabled:opacity-30">×</button>
                            </div>
                            <div class="mt-2 grid grid-cols-3 gap-2 pl-7">
                                <label class="text-[11px] font-semibold text-slate-500">Es
                                    <select x-model="parte.rol" @change="ajustar(parte, true)" class="{{ $selector }} mt-0.5">
                                        <template x-for="(nombre, rol) in roles" :key="rol">
                                            <option :value="rol" x-text="nombre" :selected="rol === parte.rol" :disabled="rol !== parte.rol && !rolesLibres.includes(rol)"></option>
                                        </template>
                                    </select>
                                </label>
                                <label class="text-[11px] font-semibold text-slate-500">Lleva
                                    <select x-model="parte.tipo" @change="ajustar(parte)" class="{{ $selector }} mt-0.5">
                                        <template x-for="tipo in tiposDe(parte.rol)" :key="tipo">
                                            <option :value="tipo" x-text="nombresTipo[tipo]" :selected="tipo === parte.tipo"></option>
                                        </template>
                                    </select>
                                </label>
                                <label class="text-[11px] font-semibold text-slate-500">Cuántos
                                    <select x-model.number="parte.largo" class="{{ $selector }} mt-0.5">
                                        <template x-for="largo in largosDe(parte)" :key="largo">
                                            <option :value="largo" x-text="largo" :selected="largo === parte.largo"></option>
                                        </template>
                                    </select>
                                </label>
                            </div>
                        </li>
                    </template>
                </ol>
                <button type="button" x-show="rolesLibres.length" @click="agregarParte()"
                        class="w-full rounded-xl border border-dashed border-slate-300 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">+ Agregar parte</button>

                <label class="block text-sm font-bold text-slate-700">Separador
                    <select x-model="borrador.separador" class="{{ $selector }} mt-1">
                        <template x-for="(nombre, separador) in separadores" :key="separador">
                            <option :value="separador" x-text="nombre" :selected="separador === borrador.separador"></option>
                        </template>
                    </select>
                </label>

                <div class="rounded-xl bg-slate-900 px-4 py-3 text-center">
                    <p class="text-xs font-bold uppercase tracking-wide text-white/60" x-text="nombreBorrador"></p>
                    <p class="font-mono text-2xl font-black tracking-wide text-white" x-text="ejemploBorrador"></p>
                </div>
                <p x-show="errorFormato" role="alert" class="rounded-xl bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-800 ring-1 ring-rose-200" x-text="errorFormato"></p>
                <p class="text-xs text-slate-500">Vale para toda la droguería. Las ubicaciones que ya estén escritas en otro formato dejan de verse en el Almacén 3D hasta que se registren de nuevo.</p>

                <div class="flex flex-wrap gap-2">
                    <button type="button" @click="guardarFormato()" :disabled="guardando"
                            class="rounded-xl bg-primary px-4 py-2.5 text-sm font-bold text-on-primary hover:bg-primary-dark disabled:opacity-40">Guardar formato</button>
                    <button type="button" @click="formatoSides()" class="rounded-xl bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">Volver al formato de SIDES</button>
                    <button type="button" @click="borrador = null" class="rounded-xl px-4 py-2.5 text-sm font-semibold text-slate-500 hover:bg-slate-50">Cancelar</button>
                </div>
            </section>
        </template>

        <p x-show="aviso" role="status" class="rounded-xl bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800 ring-1 ring-emerald-200" x-text="aviso"></p>
        <p x-show="error" role="alert" class="rounded-xl bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800 ring-1 ring-rose-200" x-text="error"></p>

        {{-- 1. Producto --}}
        <section class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
            <template x-if="!elegido">
                <form @submit.prevent="enviar()" class="space-y-2">
                    <label for="buscar-producto" class="block text-sm font-bold text-slate-700">Producto</label>
                    <input x-ref="buscar" x-model="buscar" @input="alEscribir()" id="buscar-producto" type="search" autocomplete="off" enterkeyhint="search"
                           placeholder="Código, barra o descripción"
                           class="w-full rounded-xl border-slate-300 px-3.5 py-3 text-base shadow-sm focus:border-primary focus:ring-primary">
                    <p x-show="buscando" class="text-sm text-slate-500">Buscando…</p>
                    <p x-show="!buscando && buscar.trim().length >= 2 && !productos.length" class="text-sm text-slate-500">Sin productos con ese texto.</p>
                    <ul x-show="productos.length" class="divide-y divide-slate-100 overflow-hidden rounded-xl ring-1 ring-slate-200">
                        <template x-for="producto in productos" :key="producto.codprod">
                            <li>
                                <button type="button" @click="elegir(producto)" class="block w-full px-3 py-2.5 text-left active:bg-primary-soft hover:bg-primary-soft">
                                    <span class="block truncate text-sm font-semibold text-slate-800" x-text="producto.desprod"></span>
                                    <span class="block truncate text-xs text-slate-500">
                                        <span x-text="producto.codprod"></span> ·
                                        <span class="font-mono" x-text="producto.sides || producto.erp || 'sin ubicación'"></span>
                                        <span x-show="producto.sides" class="ml-1 rounded bg-primary-soft px-1 font-bold text-primary-ink">SIDES</span>
                                    </span>
                                </button>
                            </li>
                        </template>
                    </ul>
                </form>
            </template>

            <template x-if="elegido">
                <div class="space-y-3">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Producto</p>
                            <p class="text-base font-extrabold text-slate-900" x-text="elegido.desprod"></p>
                            <p class="text-xs text-slate-500"><span x-text="elegido.codprod"></span><span x-show="elegido.barra"> · <span x-text="elegido.barra"></span></span></p>
                        </div>
                        <button type="button" @click="otro()" class="shrink-0 rounded-lg px-3 py-1.5 text-sm font-semibold text-primary-ink hover:bg-primary-soft">Cambiar</button>
                    </div>
                    <dl class="space-y-1.5 text-sm">
                        <div class="flex flex-wrap items-center gap-2" :class="elegido.sides ? '' : 'font-semibold'">
                            <dt class="w-12 text-slate-500">ERP</dt>
                            <dd class="font-mono text-slate-800" x-text="elegido.erp || 'sin ubicación'"></dd>
                            <button type="button" x-show="valida(elegido.erp) && !elegido.sides" @click="usar(elegido.erp)" class="text-xs font-semibold text-primary-ink hover:underline">Usar esta</button>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <dt class="w-12 text-slate-500">SIDES</dt>
                            <dd class="font-mono font-bold text-slate-900" x-text="elegido.sides || 'sin registrar'"></dd>
                            <button type="button" x-show="elegido.sides" @click="usar(elegido.sides)" class="text-xs font-semibold text-primary-ink hover:underline">Editar</button>
                        </div>
                        <p x-show="elegido.sides" class="text-xs text-slate-500">Registrada por <span x-text="elegido.usuario"></span> el <span x-text="elegido.fecha"></span>.</p>
                    </dl>
                </div>
            </template>
        </section>

        {{-- 2. Ubicación --}}
        <form @submit.prevent="guardar()" class="space-y-4 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
            <div class="rounded-xl bg-slate-900 px-4 py-3 text-center">
                <p class="text-xs font-bold uppercase tracking-wide text-white/60">Ubicación</p>
                <p class="font-mono text-3xl font-black tracking-wide text-white" x-text="codigo"></p>
            </div>
            <dl class="flex flex-wrap justify-center gap-1.5">
                <template x-for="[nombre, valor] in detalle" :key="nombre">
                    <div class="rounded-lg bg-slate-100 px-2 py-1 text-xs">
                        <dt class="inline text-slate-500" x-text="nombre"></dt> <dd class="inline font-bold text-slate-900" x-text="valor"></dd>
                    </div>
                </template>
            </dl>

            {{-- Una parte por cada una del formato, en su orden. Se ve si la anterior tiene valor. --}}
            <template x-for="(parte, i) in formato.partes" :key="parte.rol">
                <div x-show="visible(i)">
                    <template x-if="parte.rol === 'zona'">
                        <fieldset>
                            <legend class="mb-1.5 text-sm font-bold text-slate-700" x-text="parte.nombre"></legend>
                            <div class="flex flex-wrap gap-2">
                                <template x-for="(nombre, codigo) in zonas" :key="codigo">
                                    <button type="button" @click="valores.zona = codigo" :aria-pressed="valores.zona === codigo"
                                            :class="valores.zona === codigo ? 'bg-primary text-on-primary ring-primary' : 'bg-white text-slate-700 ring-slate-300'"
                                            class="rounded-xl px-3 py-2 text-sm font-semibold ring-1">
                                        <span class="font-mono font-bold" x-text="codigo"></span> <span x-text="nombre"></span>
                                    </button>
                                </template>
                            </div>
                        </fieldset>
                    </template>

                    <template x-if="parte.rol !== 'zona' && parte.tipo === 'numeros'">
                        <div>
                            <label :for="'ubicacion-' + parte.rol" class="mb-1.5 block text-sm font-bold text-slate-700" x-text="parte.nombre"></label>
                            <div class="flex items-center gap-1.5">
                                <button type="button" @click="cambiar(parte, -1)" class="{{ $paso }}" :aria-label="parte.nombre + ' anterior'">−</button>
                                <input :id="'ubicacion-' + parte.rol" type="number" min="1" :max="maximo(parte)" inputmode="numeric" x-model.number="valores[parte.rol]" class="{{ $numero }}">
                                <button type="button" @click="cambiar(parte, 1)" class="{{ $paso }}" :aria-label="parte.nombre + ' siguiente'">+</button>
                            </div>
                        </div>
                    </template>

                    <template x-if="parte.rol !== 'zona' && parte.tipo === 'letras'">
                        <fieldset>
                            <legend class="mb-1.5 text-sm font-bold text-slate-700" x-text="parte.nombre"></legend>
                            <div class="flex flex-wrap gap-2">
                                <template x-for="letra in letrasDe(parte)" :key="letra">
                                    <button type="button" @click="valores[parte.rol] = letra" :aria-pressed="valores[parte.rol] === letra"
                                            :class="valores[parte.rol] === letra ? 'bg-primary text-on-primary ring-primary' : 'bg-white text-slate-700 ring-slate-300'"
                                            class="size-11 rounded-xl font-mono text-lg font-bold ring-1" x-text="letra"></button>
                                </template>
                            </div>
                        </fieldset>
                    </template>

                    {{-- El estante y la posición se pueden dejar fuera: la ubicación se corta ahí. --}}
                    <button type="button" x-show="['estante', 'posicion'].includes(parte.rol) && i > 0" @click="valores[parte.rol] === '' ? valores[parte.rol] = (parte.tipo === 'letras' ? 'A' : 1) : cortar(i)"
                            :class="valores[parte.rol] === '' ? 'bg-slate-700 text-white ring-slate-700' : 'bg-white text-slate-500 ring-slate-300'"
                            class="mt-2 rounded-xl px-3 py-2 text-sm font-semibold ring-1" x-text="valores[parte.rol] === '' ? 'Sin ' + parte.nombre.toLowerCase() + ' · tocar para ponerla' : 'Sin ' + parte.nombre.toLowerCase()"></button>
                </div>
            </template>

            <label x-show="avanza && valores[avanza?.rol] !== ''" class="flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" x-model="siguiente" class="rounded border-slate-300 text-primary focus:ring-primary">
                <span>Al guardar, pasar a <span class="lowercase" x-text="avanza?.nombre"></span> siguiente</span>
            </label>

            <label class="block text-sm font-bold text-slate-700">
                Otras ubicaciones del producto <span class="font-normal text-slate-500">(opcional)</span>
                <input type="text" x-model="otras" autocomplete="off" :placeholder="ejemplo" autocapitalize="characters"
                       class="mt-1 block w-full rounded-xl border-slate-300 font-mono uppercase focus:border-primary focus:ring-primary">
                <span class="mt-1 block text-xs font-normal text-slate-500">Separadas con «;». La de arriba es la de picking.</span>
            </label>

            <button type="submit" :disabled="!elegido || guardando"
                    class="w-full rounded-xl bg-primary px-5 py-3.5 text-base font-bold text-on-primary hover:bg-primary-dark disabled:opacity-40">
                <span x-text="guardando ? 'Guardando…' : (elegido ? 'Guardar ubicación' : 'Elige un producto primero')"></span>
            </button>
            <button type="button" x-show="elegido?.sides" @click="quitar()" :disabled="guardando"
                    class="w-full rounded-xl px-4 py-2.5 text-sm font-semibold text-rose-700 hover:bg-rose-50">Quitar la de SIDES (vuelve la del ERP)</button>
        </form>

        {{-- 3. Lo último que se registró en la droguería --}}
        <section x-show="recientes.length" class="space-y-2">
            <h3 class="text-sm font-bold text-slate-700">Últimas registradas</h3>
            <ul class="divide-y divide-slate-100 overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
                <template x-for="fila in recientes" :key="fila.codprod">
                    <li class="flex items-center justify-between gap-3 px-4 py-2.5">
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-semibold text-slate-800" x-text="fila.desprod || fila.codprod"></span>
                            <span class="block truncate text-xs text-slate-500"><span x-text="fila.usuario"></span> · <span x-text="fila.fecha"></span></span>
                        </span>
                        <span class="shrink-0 font-mono text-sm font-bold text-slate-900" x-text="fila.ubicacion"></span>
                    </li>
                </template>
            </ul>
        </section>
    </div>
</x-layouts.app>
