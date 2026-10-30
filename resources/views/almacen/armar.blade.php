@php
    $paso = 'size-14 md:size-12 shrink-0 rounded-xl bg-white text-2xl font-bold text-slate-700 ring-1 ring-slate-300 active:bg-slate-100';
    $numero = 'w-14 shrink-0 text-center font-mono text-3xl font-black text-slate-900';
    $preguntas = [
        'izquierda' => ['¿Cuántos anaqueles hay a tu izquierda?', 'Cuenta todos, de la entrada hasta el fondo.'],
        'derecha' => ['¿Y a tu derecha?', 'Si no hay anaqueles de ese lado, déjalo en 0.'],
        'estantes' => ['¿Cuántos estantes tiene cada anaquel?', 'Cuenta las tablas donde se ponen productos, desde el piso hacia arriba.'],
        'posiciones' => ['¿Cuántos productos distintos caben a lo ancho de un estante?', 'Son los huecos de cada estante, de izquierda a derecha.'],
    ];
@endphp

<x-layouts.app titulo="Almacén 3D">
    <div class="mx-auto max-w-xl touch-manipulation space-y-4" x-data="armarAlmacen(@js($datos))">
        @include('almacen.partials.pestanas', ['activa' => 'almacen.armar'])

        <p x-show="aviso" role="status" class="rounded-xl bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800 ring-1 ring-emerald-200" x-text="aviso"></p>
        <p x-show="error" role="alert" class="rounded-xl bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800 ring-1 ring-rose-200" x-text="error"></p>

        {{-- Inicio: qué se va a hacer --}}
        <template x-if="paso === 'inicio'">
            <section class="space-y-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
                <div>
                    <h2 class="text-xl font-extrabold text-slate-900">Armar el almacén</h2>
                    <p class="mt-1 text-sm text-slate-600">Vas a caminar el almacén con el teléfono, pasillo por pasillo, y contar lo que ves. SIDES numera todo solo.</p>
                </div>
                <ol class="space-y-2 text-sm text-slate-700">
                    <li class="flex gap-3"><span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-primary text-xs font-bold text-on-primary">1</span> Elige la zona: picking, mayor, frío…</li>
                    <li class="flex gap-3"><span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-primary text-xs font-bold text-on-primary">2</span> Párate en la entrada del pasillo, mirando hacia adentro.</li>
                    <li class="flex gap-3"><span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-primary text-xs font-bold text-on-primary">3</span> Cuenta los anaqueles de cada lado, sus estantes y sus huecos.</li>
                    <li class="flex gap-3"><span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-primary text-xs font-bold text-on-primary">4</span> Guarda y sigue con el próximo pasillo.</li>
                </ol>
                <button type="button" @click="empezar()" class="w-full rounded-xl bg-primary px-5 py-3.5 text-base font-bold text-on-primary hover:bg-primary-dark">Empezar</button>
            </section>
        </template>

        {{-- Paso 1: zona --}}
        <template x-if="paso === 'zona'">
            <section class="space-y-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
                <div>
                    <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Paso 1</p>
                    <h2 class="text-xl font-extrabold text-slate-900">¿En qué zona estás?</h2>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <template x-for="(nombre, codigo) in zonas" :key="codigo">
                        <button type="button" @click="elegirZona(codigo)"
                                class="rounded-xl bg-white px-3 py-3 text-left ring-1 ring-slate-300 hover:bg-primary-soft active:bg-primary-soft">
                            <span class="block font-mono text-lg font-black text-slate-900" x-text="codigo"></span>
                            <span class="block text-sm text-slate-600" x-text="nombre"></span>
                        </button>
                    </template>
                </div>
                <form @submit.prevent="crearZona()" class="space-y-2 rounded-xl bg-slate-50 p-3 ring-1 ring-slate-200">
                    <p class="text-sm font-bold text-slate-700">Otra zona</p>
                    <div class="flex gap-2">
                        <input type="text" x-model="codigoNuevo" maxlength="2" placeholder="QX" aria-label="Código de 2 letras" autocapitalize="characters" autocomplete="off"
                               class="w-16 rounded-xl border-slate-300 text-center font-mono uppercase focus:border-primary focus:ring-primary">
                        <input type="text" x-model="nombreNuevo" maxlength="40" placeholder="Nombre, por ejemplo Quirúrgicos" aria-label="Nombre de la zona"
                               class="min-w-0 flex-1 rounded-xl border-slate-300 focus:border-primary focus:ring-primary">
                    </div>
                    <button type="submit" class="min-h-11 w-full rounded-xl bg-white px-4 py-2 text-sm font-semibold text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">Usar esta zona</button>
                </form>
                <button type="button" x-show="pasillos.length" @click="paso = 'resumen'" class="w-full rounded-xl px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100">Volver al resumen</button>
            </section>
        </template>

        {{-- Paso 2: un pasillo --}}
        <template x-if="paso === 'pasillo'">
            <section class="space-y-4">
                <div class="rounded-2xl bg-slate-900 p-4 text-white">
                    <p class="text-xs font-bold uppercase tracking-wide text-white/60">
                        <span x-text="nombre(zona) + (nombreZona ? ' (' + nombreZona + ')' : '')"></span> · Paso 2
                    </p>
                    <div class="mt-1 flex items-center justify-between gap-3">
                        <h2 class="text-xl font-extrabold">Pasillo</h2>
                        <div class="flex items-center gap-1.5">
                            <button type="button" @click="cambiar('pasillo', -1)" class="size-12 md:size-10 rounded-xl bg-white/10 text-xl font-bold" aria-label="Pasillo anterior">−</button>
                            <span class="w-12 text-center font-mono text-3xl font-black" x-text="String(actual.pasillo).padStart(2, '0')"></span>
                            <button type="button" @click="cambiar('pasillo', 1)" class="size-12 md:size-10 rounded-xl bg-white/10 text-xl font-bold" aria-label="Pasillo siguiente">+</button>
                        </div>
                    </div>
                    <p class="mt-2 text-sm text-white/80">Párate en la entrada del pasillo, mirando hacia adentro.</p>
                    <p x-show="repetido" class="mt-2 rounded-lg bg-amber-400/20 px-2 py-1 text-xs font-semibold text-amber-200">Este pasillo ya estaba registrado: se reemplaza con lo que cuentes ahora.</p>
                </div>

                <div class="space-y-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                    @foreach ($preguntas as $campo => [$pregunta, $ayuda])
                        <div class="{{ $loop->first ? '' : 'border-t border-slate-100 pt-3' }}">
                            <p class="text-sm font-bold text-slate-800">{{ $pregunta }}</p>
                            <p class="text-xs text-slate-500">{{ $ayuda }}</p>
                            <div class="mt-2 flex items-center justify-center gap-3">
                                <button type="button" @click="cambiar('{{ $campo }}', -1)" class="{{ $paso }}" aria-label="Menos">−</button>
                                <span class="{{ $numero }}" x-text="actual.{{ $campo }}" aria-live="polite"></span>
                                <button type="button" @click="cambiar('{{ $campo }}', 1)" class="{{ $paso }}" aria-label="Más">+</button>
                            </div>
                        </div>
                    @endforeach
                </div>

                {{-- Cómo queda numerado: el pasillo visto desde arriba y un anaquel de frente. --}}
                <div class="space-y-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                    <p class="text-sm font-bold text-slate-800">Así queda numerado</p>
                    <div class="grid grid-cols-[1fr_4.5rem_1fr] gap-2">
                        <div class="flex flex-col-reverse gap-1">
                            <template x-for="numero in izquierdos" :key="numero">
                                <div class="rounded-md bg-slate-700 py-1 text-center font-mono text-xs font-bold text-white" x-text="numero"></div>
                            </template>
                            <p x-show="!izquierdos.length" class="py-2 text-center text-xs text-slate-400">Sin anaqueles</p>
                        </div>
                        <div class="flex flex-col items-center justify-end gap-1 rounded-md bg-slate-100 px-1 py-2 text-center">
                            <span class="font-mono text-xs font-bold text-slate-500" x-text="'Pasillo ' + String(actual.pasillo).padStart(2, '0')"></span>
                            <span class="mt-auto text-2xl leading-none text-primary-ink" aria-hidden="true">↑</span>
                            <span class="text-[11px] font-bold leading-tight text-primary-ink">Tú entras aquí</span>
                        </div>
                        <div class="flex flex-col-reverse gap-1">
                            <template x-for="numero in derechos" :key="numero">
                                <div class="rounded-md bg-slate-700 py-1 text-center font-mono text-xs font-bold text-white" x-text="numero"></div>
                            </template>
                            <p x-show="!derechos.length" class="py-2 text-center text-xs text-slate-400">Sin anaqueles</p>
                        </div>
                    </div>
                    <p class="text-xs text-slate-500">Impares a tu izquierda y pares a tu derecha; el 01 y el 02 son los más cercanos a la entrada.</p>

                    <div class="border-t border-slate-100 pt-3">
                        <p class="mb-2 text-xs font-semibold text-slate-600">Un anaquel visto de frente:</p>
                        <div class="overflow-x-auto">
                            <table class="mx-auto border-separate border-spacing-0.5 text-center font-mono text-[11px]">
                                <thead>
                                    <tr>
                                        <th></th>
                                        <template x-for="hueco in huecos" :key="hueco">
                                            <th class="min-w-5 font-bold text-slate-500" x-text="hueco"></th>
                                        </template>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="letra in estantes" :key="letra">
                                        <tr>
                                            <th class="pr-1 font-bold text-slate-500" x-text="letra"></th>
                                            <template x-for="hueco in huecos" :key="hueco">
                                                <td class="h-5 min-w-5 rounded-sm" :class="letra === 'A' && hueco === 1 ? 'bg-orange-500' : 'bg-slate-200'"></td>
                                            </template>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                        <p class="mt-2 text-xs text-slate-500">
                            El estante A es el de abajo y el hueco 1 el de la izquierda. El hueco naranja del primer anaquel es
                            <span class="font-mono font-bold text-slate-800" x-text="ejemplo"></span>.
                        </p>
                    </div>
                </div>

                {{-- En el teléfono queda a la vista: el dibujo crece con cada anaquel y no hay que bajar a buscarlo. --}}
                <button type="button" @click="guardar(true)" :disabled="guardando"
                        class="sticky bottom-3 z-10 w-full rounded-xl bg-primary px-5 py-3.5 text-base font-bold text-on-primary shadow-lg hover:bg-primary-dark disabled:opacity-50 md:static md:shadow-none"
                        x-text="guardando ? 'Guardando…' : (antes ? 'Guardar cambios y seguir' : 'Guardar y seguir con el pasillo ' + String(Math.min(actual.pasillo + 1, 99)).padStart(2, '0'))"></button>
                <div class="space-y-2">
                    <button type="button" @click="guardar(false)" :disabled="guardando"
                            class="w-full rounded-xl bg-white px-5 py-3 text-sm font-bold text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50 disabled:opacity-50">Guardar y terminar esta zona</button>
                    <button type="button" @click="paso = pasillos.length ? 'resumen' : 'zona'" :disabled="guardando"
                            class="min-h-11 w-full rounded-xl px-5 py-2.5 text-sm font-semibold text-slate-600 hover:bg-slate-100">Salir sin guardar este pasillo</button>
                </div>
            </section>
        </template>

        {{-- Resumen: lo que ya está registrado --}}
        <template x-if="paso === 'resumen'">
            <section class="space-y-4">
                <div>
                    <h2 class="text-xl font-extrabold text-slate-900">Tu almacén</h2>
                    <p class="text-sm text-slate-500">
                        <span x-text="pasillos.length"></span> <span x-text="pasillos.length === 1 ? 'pasillo' : 'pasillos'"></span> ·
                        <span x-text="totalAnaqueles"></span> anaqueles
                    </p>
                </div>

                <p x-show="otraNumeracion" class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-amber-200">
                    Este almacén tenía otra numeración en los ajustes avanzados. Lo que armes aquí usa la de SIDES:
                    impares a la izquierda, estante A abajo y hueco 1 a la izquierda.
                </p>

                <template x-for="grupo in porZona" :key="grupo.zona">
                    <div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
                        <div class="flex items-center justify-between gap-3 bg-slate-50 px-4 py-2.5">
                            <p class="text-sm font-bold text-slate-900"><span class="font-mono" x-text="grupo.zona"></span> · <span x-text="grupo.nombre"></span></p>
                            <button type="button" @click="elegirZona(grupo.zona)" class="min-h-11 px-2 text-sm font-semibold text-primary-ink hover:underline">+ Pasillo</button>
                        </div>
                        <ul class="divide-y divide-slate-100">
                            <template x-for="p in grupo.pasillos" :key="p.clave">
                                <li class="flex items-center gap-3 px-4 py-2.5">
                                    <span class="w-10 shrink-0 font-mono text-lg font-black text-slate-900" x-text="String(p.pasillo).padStart(2, '0')"></span>
                                    <span class="min-w-0 flex-1 text-xs text-slate-600">
                                        <span x-text="p.izquierda"></span> a la izquierda · <span x-text="p.derecha"></span> a la derecha<br>
                                        <span x-text="p.estantes"></span> estantes × <span x-text="p.posiciones"></span> huecos
                                    </span>
                                    <button type="button" @click="editar(p)" class="min-h-11 rounded-lg px-2 py-1 text-sm font-semibold text-primary-ink hover:bg-primary-soft">Editar</button>
                                    <button type="button" @click="quitar(p)" :disabled="guardando" class="min-h-11 rounded-lg px-2 py-1 text-sm font-semibold text-rose-700 hover:bg-rose-50" aria-label="Quitar pasillo">Quitar</button>
                                </li>
                            </template>
                        </ul>
                    </div>
                </template>

                <div class="grid gap-2">
                    <button type="button" @click="empezar()" class="w-full rounded-xl bg-white px-5 py-3 text-sm font-bold text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">Agregar otra zona</button>
                    <a href="{{ route('almacen.index') }}" class="block w-full rounded-xl bg-primary px-5 py-3.5 text-center text-base font-bold text-on-primary hover:bg-primary-dark">Ver el almacén en 3D</a>
                    <a href="{{ route('almacen.ubicaciones') }}" class="block w-full rounded-xl px-5 py-2.5 text-center text-sm font-semibold text-primary-ink hover:bg-primary-soft">Siguiente: registrar dónde está cada producto</a>
                </div>
            </section>
        </template>
    </div>
</x-layouts.app>
