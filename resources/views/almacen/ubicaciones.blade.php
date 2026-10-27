@php
    $paso = 'size-11 shrink-0 rounded-xl bg-white text-xl font-bold text-slate-700 ring-1 ring-slate-300 active:bg-slate-100';
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
                <span class="font-mono font-bold text-slate-700">ZONA-PASILLO-ANAQUEL-ESTANTE-POSICIÓN</span>.
                La ubicación de SIDES gana sobre la del ERP.
            </p>
        </div>

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

            <fieldset>
                <legend class="mb-1.5 text-sm font-bold text-slate-700">Zona</legend>
                <div class="flex flex-wrap gap-2">
                    <template x-for="(nombre, codigo) in zonas" :key="codigo">
                        <button type="button" @click="zona = codigo" :aria-pressed="zona === codigo"
                                :class="zona === codigo ? 'bg-primary text-on-primary ring-primary' : 'bg-white text-slate-700 ring-slate-300'"
                                class="rounded-xl px-3 py-2 text-sm font-semibold ring-1">
                            <span class="font-mono font-bold" x-text="codigo"></span> <span x-text="nombre"></span>
                        </button>
                    </template>
                </div>
            </fieldset>

            <div class="grid grid-cols-2 gap-3">
                @foreach (['pasillo' => 'Pasillo', 'anaquel' => 'Anaquel'] as $campo => $etiqueta)
                    <div>
                        <label for="ubicacion-{{ $campo }}" class="mb-1.5 block text-sm font-bold text-slate-700">{{ $etiqueta }}</label>
                        <div class="flex items-center gap-1">
                            <button type="button" @click="cambiar('{{ $campo }}', -1)" class="{{ $paso }}" aria-label="{{ $etiqueta }} anterior">−</button>
                            <input id="ubicacion-{{ $campo }}" type="number" min="1" max="99" inputmode="numeric" x-model.number="{{ $campo }}" class="{{ $numero }}">
                            <button type="button" @click="cambiar('{{ $campo }}', 1)" class="{{ $paso }}" aria-label="{{ $etiqueta }} siguiente">+</button>
                        </div>
                    </div>
                @endforeach
            </div>

            <fieldset>
                <legend class="mb-1.5 text-sm font-bold text-slate-700">Estante</legend>
                <div class="flex flex-wrap gap-2">
                    <template x-for="letra in letras" :key="letra">
                        <button type="button" @click="estante = letra" :aria-pressed="estante === letra"
                                :class="estante === letra ? 'bg-primary text-on-primary ring-primary' : 'bg-white text-slate-700 ring-slate-300'"
                                class="size-12 rounded-xl font-mono text-lg font-bold ring-1" x-text="letra"></button>
                    </template>
                    <button type="button" @click="estante = ''; posicion = ''" :aria-pressed="!estante"
                            :class="!estante ? 'bg-slate-700 text-white ring-slate-700' : 'bg-white text-slate-500 ring-slate-300'"
                            class="h-12 rounded-xl px-3 text-sm font-semibold ring-1">Sin estante</button>
                </div>
            </fieldset>

            <div x-show="estante">
                <label for="ubicacion-posicion" class="mb-1.5 block text-sm font-bold text-slate-700">Posición</label>
                <div class="flex items-center gap-1.5">
                    <button type="button" @click="cambiar('posicion', -1)" class="{{ $paso }}" aria-label="Posición anterior">−</button>
                    <input id="ubicacion-posicion" type="number" min="1" max="99" inputmode="numeric" x-model.number="posicion" class="{{ $numero }}">
                    <button type="button" @click="cambiar('posicion', 1)" class="{{ $paso }}" aria-label="Posición siguiente">+</button>
                </div>
                <label class="mt-2 flex items-center gap-2 text-sm text-slate-600">
                    <input type="checkbox" x-model="siguiente" class="rounded border-slate-300 text-primary focus:ring-primary">
                    Al guardar, pasar a la posición siguiente
                </label>
            </div>

            <label class="block text-sm font-bold text-slate-700">
                Otras ubicaciones del producto <span class="font-normal text-slate-500">(opcional)</span>
                <input type="text" x-model="otras" autocomplete="off" placeholder="MY-02-03;FR" autocapitalize="characters"
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
