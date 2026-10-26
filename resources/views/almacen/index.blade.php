@php
    $datos = [
        'plano' => $plano,
        'urls' => ['buscar' => route('almacen.buscar')],
    ];
    $numero = fn ($valor) => number_format((int) $valor, 0, ',', '.');
    $anaqueles = collect($plano['zonas'])->flatMap(fn ($zona) => $zona['pasillos'])->sum(fn ($pasillo) => count($pasillo['anaqueles']));
@endphp

<x-layouts.app titulo="Almacén 3D">
    <div class="mx-auto max-w-6xl space-y-4" x-data="almacenVista(@js($datos))">
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
            <p class="mt-3 inline-block rounded-xl bg-slate-900 px-4 py-2 font-mono text-lg font-bold tracking-wide text-white">ZONA-PASILLO-ANAQUEL-ESTANTE-POSICIÓN</p>
            <ul class="mt-3 space-y-1 text-sm text-slate-600">
                <li><span class="font-mono font-bold text-slate-800">PK-03-12-B-04</span> · zona Picking, pasillo 3, anaquel 12, estante B, posición 4.</li>
                <li>Anaqueles impares a la izquierda del pasillo y pares a la derecha. El estante A es el de abajo.</li>
                <li>Se puede cortar por la derecha (<span class="font-mono">FR</span>, <span class="font-mono">MY-02</span>) y varias ubicaciones van separadas con <span class="font-mono">;</span>.</li>
            </ul>
            @if ($plano['ubicaciones'])
                <p class="mt-3 text-sm text-amber-800">Ninguna de las {{ $numero($plano['ubicaciones']) }} ubicaciones del catálogo sigue el formato todavía.</p>
            @endif
            <button type="button" @click="verEjemplo()" class="mt-4 rounded-xl bg-primary px-5 py-2.5 text-sm font-bold text-on-primary hover:bg-primary-dark">Ver un almacén de ejemplo</button>
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
                </section>
            </div>

            <div class="relative h-[60vh] min-h-80 overflow-hidden rounded-2xl ring-1 ring-slate-200 lg:h-[65vh]">
                <div x-ref="visor" class="absolute inset-0"></div>
                <p x-show="cargando" class="absolute inset-0 flex items-center justify-center bg-slate-100 text-sm font-semibold text-slate-500">Armando el almacén…</p>
                <p class="pointer-events-none absolute bottom-2 left-3 text-xs text-slate-500">Arrastra para girar · rueda o dos dedos para acercar</p>
            </div>
        </div>
    </div>
</x-layouts.app>
