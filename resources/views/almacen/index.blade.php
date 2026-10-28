@php
    $usuario = auth()->user();
    $puedeUbicar = \App\Support\MenuSides::puede($usuario, $usuario->cfg, 'ubicaciones');
    $formatoUbicacion = $plano['config']['formato'];
    $datos = [
        'plano' => $plano,
        'urls' => array_filter([
            'buscar' => route('almacen.buscar'),
            // Con permiso de ubicaciones el producto se arrastra a otro hueco y se guarda.
            'guardar' => $puedeUbicar ? route('almacen.ubicaciones.guardar') : null,
        ]),
    ];
    $numero = fn ($valor) => number_format((int) $valor, 0, ',', '.');
    $anaqueles = collect($plano['zonas'])->flatMap(fn ($zona) => $zona['pasillos'])->sum(fn ($pasillo) => count($pasillo['anaqueles']));
@endphp

<x-layouts.app titulo="Almacén 3D">
    <div class="mx-auto max-w-6xl space-y-4" x-data="almacenVista(@js($datos))">
        @include('almacen.partials.pestanas', ['activa' => 'almacen.index'])

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-xl font-extrabold text-slate-900">Almacén 3D</h2>
                <p class="text-sm text-slate-500">
                    @if ($plano['zonas'])
                        {{ $numero(count($plano['zonas'])) }} {{ count($plano['zonas']) === 1 ? 'zona' : 'zonas' }} · {{ $numero($anaqueles) }} anaqueles ·
                        {{ $numero($plano['reconocidas']) }} de {{ $numero($plano['ubicaciones']) }} ubicaciones del catálogo con el formato
                    @else
                        Los anaqueles del almacén y dónde está cada producto.
                    @endif
                </p>
            </div>
            <button type="button" x-show="!vacio || ejemplo" @click="vistaGeneral()"
                    class="rounded-xl bg-white px-4 py-2 text-sm font-semibold text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">Ver todo el almacén</button>
        </div>

        {{-- Sin ubicaciones en el formato: se explica cuál es y se ofrece un ejemplo. --}}
        <section x-show="vacio && !ejemplo" class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
            <h3 class="text-lg font-extrabold text-slate-900">Todavía no hay ubicaciones para dibujar el almacén</h3>
            <p class="mt-1 max-w-2xl text-sm text-slate-600">
                El almacén se arma solo con las ubicaciones que manda el ERP en el catálogo, cuando siguen este formato:
            </p>
            <p class="mt-3 inline-block rounded-xl bg-slate-900 px-4 py-2 font-mono text-lg font-bold tracking-wide text-white">{{ \App\Support\UbicacionAlmacen::nombre($formatoUbicacion) }}</p>
            <ul class="mt-3 space-y-1 text-sm text-slate-600">
                <li><span class="font-mono font-bold text-slate-800">{{ \App\Support\UbicacionAlmacen::ejemplo($formatoUbicacion) }}</span> · en Picking, pasillo 3, anaquel 12, segundo estante, posición 4.</li>
                <li>Anaqueles impares a la izquierda del pasillo y pares a la derecha. El estante A es el de abajo.</li>
                <li>Se puede cortar por la derecha (<span class="font-mono">FR</span>, <span class="font-mono">MY-02</span>) y varias ubicaciones van separadas con <span class="font-mono">;</span>.</li>
            </ul>
            @if ($plano['ubicaciones'])
                <p class="mt-3 text-sm text-amber-800">Ninguna de las {{ $numero($plano['ubicaciones']) }} ubicaciones del catálogo sigue el formato todavía.</p>
            @endif
            <div class="mt-4 flex flex-wrap gap-2">
                @if ($puedeUbicar)
                    <a href="{{ route('almacen.armar') }}" class="rounded-xl bg-primary px-5 py-2.5 text-sm font-bold text-on-primary hover:bg-primary-dark">Armar el almacén con el teléfono</a>
                    <a href="{{ route('almacen.ubicaciones') }}" class="rounded-xl bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">Registrar ubicaciones</a>
                @endif
                <button type="button" @click="verEjemplo()"
                        class="{{ $puedeUbicar ? 'bg-white text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50 font-semibold' : 'bg-primary text-on-primary hover:bg-primary-dark font-bold' }} rounded-xl px-5 py-2.5 text-sm">Ver un almacén de ejemplo</button>
            </div>
        </section>

        <div x-show="!vacio || ejemplo" x-cloak class="grid gap-4 lg:grid-cols-[20rem_1fr]">
            <div class="space-y-3">
                <form @submit.prevent="enviar()" class="space-y-2">
                    <label for="buscar-almacen" class="block text-sm font-bold text-slate-700">Buscar producto o ubicación</label>
                    <input x-model="buscar" @input="alEscribir()" id="buscar-almacen" type="search" autocomplete="off"
                           :placeholder="ejemplo ? 'PK-03-12-B-04' : 'Código, barra, descripción o PK-03-12-B-04'"
                           class="w-full rounded-xl border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-primary focus:ring-primary">
                    <p x-show="ejemplo" class="text-xs text-slate-500">Es un almacén de ejemplo: prueba con una ubicación como PK-04-11-A-06 o MY-02-03.</p>
                </form>

                <p x-show="buscando" class="text-sm text-slate-500">Buscando…</p>
                <ul x-show="productos.length" class="divide-y divide-slate-100 overflow-hidden rounded-xl bg-white ring-1 ring-slate-200">
                    <template x-for="producto in productos" :key="producto.codprod">
                        <li>
                            <button type="button" @click="elegir(producto)" class="block w-full px-3 py-2 text-left hover:bg-primary-soft">
                                <span class="block truncate text-sm font-semibold text-slate-800" x-text="producto.desprod"></span>
                                <span class="block text-xs text-slate-500" x-text="producto.codprod + ' · ' + (producto.ubicacion || 'sin ubicación')"></span>
                            </button>
                        </li>
                    </template>
                </ul>

                <p x-show="mensaje" x-cloak class="rounded-xl bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-900 ring-1 ring-amber-200" x-text="mensaje"></p>
                @if ($puedeUbicar)
                    <a x-show="elegido && !elegido.ubicaciones.length" x-cloak :href="'{{ route('almacen.ubicaciones') }}?q=' + encodeURIComponent(elegido?.codprod ?? '')"
                       class="block rounded-xl bg-primary px-4 py-2.5 text-center text-sm font-bold text-on-primary hover:bg-primary-dark">Registrar su ubicación</a>
                @endif
                <p x-show="aviso" x-cloak role="status" class="rounded-xl bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-800 ring-1 ring-emerald-200" x-text="aviso"></p>

                <section x-show="ubicacion" x-cloak class="overflow-hidden rounded-2xl bg-white shadow-sm ring-2 ring-orange-400">
                    <div class="bg-orange-500 px-4 py-2 text-white">
                        <p class="truncate text-xs font-bold uppercase tracking-wide text-white/80" x-text="elegido ? elegido.desprod : 'Ubicación'"></p>
                        <p class="font-mono text-2xl font-black" x-text="ubicacion?.codigo"></p>
                    </div>
                    {{-- En el teléfono el detalle va en etiquetas, para que el 3D quede a la vista. --}}
                    <dl class="flex flex-wrap gap-1.5 p-3 lg:block lg:divide-y lg:divide-slate-100 lg:px-4 lg:py-0">
                        <template x-for="[nombre, valor] in detalle" :key="nombre">
                            <div class="rounded-lg bg-slate-100 px-2 py-1 text-xs lg:flex lg:justify-between lg:gap-3 lg:rounded-none lg:bg-transparent lg:px-0 lg:py-1.5 lg:text-sm">
                                <dt class="inline text-slate-500" x-text="nombre"></dt>
                                <dd class="inline font-bold text-slate-900" x-text="valor"></dd>
                            </div>
                        </template>
                    </dl>
                    <p x-show="elegido?.ubicaciones.length > 1" class="border-t border-slate-100 px-4 py-2 text-xs text-slate-500">
                        En celeste, las otras ubicaciones del producto.
                    </p>
                    @if ($puedeUbicar)
                        <p x-show="arrastrable" class="border-t border-slate-100 bg-emerald-50 px-4 py-2 text-xs font-semibold text-emerald-900">
                            Para moverlo, agarra la caja naranja y suéltala en otro hueco del almacén.
                        </p>
                    @endif
                </section>
            </div>

            <div class="relative h-[60vh] min-h-80 overflow-hidden rounded-2xl ring-1 ring-slate-200 lg:h-[65vh]">
                <div x-ref="visor" class="absolute inset-0"></div>
                <p x-show="cargando" class="absolute inset-0 flex items-center justify-center bg-slate-100 text-sm font-semibold text-slate-500">Armando el almacén…</p>
                <p class="pointer-events-none absolute bottom-2 left-3 right-40 text-xs text-slate-500"
                   x-text="arrastrable ? 'Agarra la caja naranja para moverla · arrastra el resto para desplazarte' : 'Arrastra para desplazarte · clic derecho o dos dedos para girar · rueda o pellizco para acercar'"></p>
                @include('almacen.partials.controles-3d')

                {{-- Producto soltado en otro hueco: se confirma antes de guardar. --}}
                <div x-show="movimiento" x-cloak role="dialog" aria-label="Mover producto"
                     class="absolute inset-x-0 top-0 m-3 space-y-2 rounded-2xl bg-white p-3 shadow-lg ring-1 ring-slate-200">
                    <p class="truncate text-sm font-bold text-slate-900" x-text="'Mover ' + (elegido?.desprod ?? '')"></p>
                    <p class="flex flex-wrap items-center gap-2 font-mono text-sm font-bold">
                        <span class="rounded-lg bg-orange-100 px-2 py-1 text-orange-900" x-text="movimiento?.desde"></span>
                        <span class="text-slate-400" aria-hidden="true">→</span>
                        <span class="rounded-lg bg-emerald-100 px-2 py-1 text-emerald-900" x-text="movimiento?.hacia"></span>
                    </p>
                    <div class="flex gap-2">
                        <button type="button" @click="confirmarMovimiento()" :disabled="guardando"
                                class="flex-1 rounded-xl bg-primary px-4 py-2.5 text-sm font-bold text-on-primary hover:bg-primary-dark disabled:opacity-50"
                                x-text="guardando ? 'Guardando…' : 'Guardar aquí'"></button>
                        <button type="button" @click="cancelarMovimiento()" :disabled="guardando"
                                class="rounded-xl px-4 py-2.5 text-sm font-semibold text-slate-600 hover:bg-slate-100">Cancelar</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>
