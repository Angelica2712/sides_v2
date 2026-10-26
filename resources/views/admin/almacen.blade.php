@php
    use App\Support\ConfigAlmacen;

    $config = $plano['config'];
    $datos = ['plano' => $plano, 'config' => [...$config, 'zonas' => (object) $config['zonas'], 'disposicion' => (object) $config['disposicion']]];
    $opciones = [
        'numeracion' => ['¿Cómo se numeran los anaqueles en cada pasillo?', ConfigAlmacen::NUMERACIONES],
        'estanteA' => ['¿Dónde está el estante A?', ConfigAlmacen::ESTANTE_A],
        'posicion1' => ['¿Dónde está la posición 1?', ConfigAlmacen::POSICION_1],
    ];
@endphp

<x-layouts.app titulo="Almacén">
    <div class="mx-auto max-w-6xl space-y-4" x-data="almacenConfig(@js($datos))">
        <a href="{{ route('admin.index') }}" class="text-sm font-semibold text-primary-ink hover:underline">← Volver a droguerías</a>

        <div>
            <h2 class="text-xl font-extrabold text-slate-900">Almacén de {{ $drogueria->nombre ?: $drogueria->codisb }}</h2>
            <p class="max-w-3xl text-sm text-slate-500">
                El código de ubicación es siempre <span class="font-mono font-bold text-slate-700">ZONA-PASILLO-ANAQUEL-ESTANTE-POSICIÓN</span>.
                Aquí se indica cómo está acomodado físicamente este almacén para que el Almacén 3D lo dibuje igual.
            </p>
        </div>

        <div class="grid gap-4 xl:grid-cols-[22rem_1fr]">
            <form method="POST" action="{{ route('admin.almacen.update', $drogueria->codisb) }}" class="space-y-4">
                @csrf
                @method('PUT')

                @if ($errors->any())
                    <div class="rounded-xl bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800 ring-1 ring-rose-200">{{ $errors->first() }}</div>
                @endif

                @foreach ($opciones as $campo => [$pregunta, $valores])
                    <fieldset class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                        <legend class="sr-only">{{ $pregunta }}</legend>
                        <p class="mb-2 text-sm font-bold text-slate-800" aria-hidden="true">{{ $pregunta }}</p>
                        <div class="space-y-2">
                            @foreach ($valores as $valor => [$etiqueta, $ayuda])
                                <label class="flex cursor-pointer gap-3 rounded-xl p-3 ring-1 transition"
                                       :class="config.{{ $campo }} === '{{ $valor }}' ? 'bg-primary-soft ring-primary' : 'ring-slate-200 hover:bg-slate-50'">
                                    <input type="radio" name="{{ $campo }}" value="{{ $valor }}" x-model="config.{{ $campo }}"
                                           class="mt-0.5 border-slate-300 text-primary focus:ring-primary">
                                    <span>
                                        <span class="block text-sm font-bold text-slate-900">{{ $etiqueta }}</span>
                                        <span class="block text-xs text-slate-500">{{ $ayuda }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                @endforeach

                <fieldset class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                    <legend class="sr-only">Tamaño de los anaqueles</legend>
                    <p class="text-sm font-bold text-slate-800" aria-hidden="true">Tamaño de los anaqueles</p>
                    <p class="mb-3 text-xs text-slate-500">Si una ubicación del catálogo llega más alto, ese anaquel se dibuja más grande.</p>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="block text-sm font-semibold text-slate-700">
                            Estantes
                            <input type="number" name="estantes" min="1" max="12" required x-model.number="config.estantes"
                                   class="mt-1 block w-full rounded-xl border-slate-300 focus:border-primary focus:ring-primary">
                        </label>
                        <label class="block text-sm font-semibold text-slate-700">
                            Posiciones por estante
                            <input type="number" name="posiciones" min="1" max="30" required x-model.number="config.posiciones"
                                   class="mt-1 block w-full rounded-xl border-slate-300 focus:border-primary focus:ring-primary">
                        </label>
                    </div>
                </fieldset>

                <fieldset class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                    <legend class="sr-only">Nombres de las zonas</legend>
                    <p class="text-sm font-bold text-slate-800" aria-hidden="true">Nombres de las zonas</p>
                    <p class="mb-3 text-xs text-slate-500">Vacío deja el nombre de SIDES.</p>
                    <div class="space-y-2">
                        @foreach ($zonas as $codigo => $nombre)
                            <label class="flex items-center gap-3">
                                <span class="w-10 shrink-0 rounded-lg bg-slate-900 py-1.5 text-center font-mono text-sm font-bold text-white">{{ $codigo }}</span>
                                <span class="sr-only">Nombre de la zona {{ $codigo }}</span>
                                <input type="text" name="zonas[{{ $codigo }}]" maxlength="40" placeholder="{{ $nombre ?: 'Sin nombre' }}"
                                       x-model="config.zonas['{{ $codigo }}']"
                                       class="block w-full rounded-xl border-slate-300 py-1.5 text-sm focus:border-primary focus:ring-primary">
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                <input type="hidden" name="disposicion" :value="JSON.stringify(config.disposicion)">
                <p x-show="movidos" x-cloak class="rounded-xl bg-amber-50 px-3 py-2 text-xs text-amber-900 ring-1 ring-amber-200">
                    <span class="font-bold" x-text="movidos"></span> anaqueles ubicados a mano en el plano.
                </p>

                <div class="flex flex-wrap items-center gap-2">
                    <button type="submit" class="rounded-xl bg-primary px-5 py-2.5 text-sm font-bold text-on-primary hover:bg-primary-dark">Guardar acomodo</button>
                    <button type="submit" form="restablecer" class="rounded-xl px-4 py-2.5 text-sm font-semibold text-slate-600 hover:bg-slate-100">Volver al recomendado</button>
                </div>
            </form>

            <div class="order-first space-y-3 xl:sticky xl:top-4 xl:order-none xl:self-start">
                <div class="flex flex-wrap items-end gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                    <label class="block min-w-48 flex-1 text-sm font-bold text-slate-700">
                        Probar una ubicación
                        <input type="text" x-model="prueba" @input.debounce.300ms="resaltar()" autocomplete="off" placeholder="PK-01-03-A-01"
                               class="mt-1 block w-full rounded-xl border-slate-300 font-mono focus:border-primary focus:ring-primary">
                    </label>
                    <dl class="flex flex-wrap gap-1.5">
                        <template x-for="[nombre, valor] in detalle" :key="nombre">
                            <div class="rounded-lg bg-orange-50 px-2 py-1 text-xs text-orange-900 ring-1 ring-orange-200">
                                <dt class="inline" x-text="nombre"></dt> <dd class="inline font-bold" x-text="valor"></dd>
                            </div>
                        </template>
                    </dl>
                </div>
                <p x-show="ejemplo" class="text-xs text-slate-500">Esta droguería aún no manda ubicaciones en el formato: la vista previa usa un almacén de ejemplo.</p>

                <div class="flex gap-1 rounded-xl bg-slate-200 p-1" role="tablist" aria-label="Vista">
                    <button type="button" role="tab" :aria-selected="vista === '3d'" @click="vista = '3d'"
                            :class="vista === '3d' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900'"
                            class="flex-1 rounded-lg px-3 py-2 text-sm font-bold">Vista 3D</button>
                    <button type="button" role="tab" :aria-selected="vista === 'plano'" @click="vista = 'plano'; $nextTick(() => $refs.plano.focus())"
                            :class="vista === 'plano' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900'"
                            class="flex-1 rounded-lg px-3 py-2 text-sm font-bold">Plano: mover anaqueles</button>
                </div>

                {{-- Plano visto desde arriba: se arrastran los anaqueles a su lugar real. --}}
                <section x-show="vista === 'plano'" x-cloak class="space-y-2">
                    <div class="flex flex-wrap items-center gap-2 rounded-2xl bg-white p-3 shadow-sm ring-1 ring-slate-200">
                        <label class="flex items-center gap-2 text-sm font-semibold text-slate-700">
                            <input type="checkbox" x-model="moverPasillo" class="rounded border-slate-300 text-primary focus:ring-primary">
                            Mover el pasillo completo
                        </label>
                        <span class="mx-1 h-6 w-px bg-slate-200" aria-hidden="true"></span>
                        <button type="button" @click="girar()" :disabled="!elegido"
                                class="rounded-lg bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50 disabled:opacity-40">Girar 90°</button>
                        <button type="button" @click="restaurar()" :disabled="!elegido"
                                class="rounded-lg bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50 disabled:opacity-40">Devolver a su lugar</button>
                        <span class="flex-1"></span>
                        <button type="button" @click="acercar(0.8)" aria-label="Acercar" class="size-8 rounded-lg bg-white text-lg font-bold text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">+</button>
                        <button type="button" @click="acercar(1.25)" aria-label="Alejar" class="size-8 rounded-lg bg-white text-lg font-bold text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">−</button>
                        <button type="button" @click="encajar()" class="rounded-lg bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">Ver todo</button>
                        <button type="button" @click="restaurarTodo()" x-show="movidos" class="rounded-lg px-3 py-1.5 text-sm font-semibold text-rose-700 hover:bg-rose-50">Acomodar todo automático</button>
                    </div>

                    <p class="text-xs text-slate-500">
                        Arrastra un anaquel para moverlo; la raya naranja es su frente (el lado del pasillo). Con un anaquel elegido,
                        las flechas lo mueven 10 cm y la tecla R lo gira. Arrastra el fondo para desplazarte. Los cambios se guardan con «Guardar acomodo».
                    </p>
                    <p x-show="elegido" class="text-sm text-slate-700">
                        Elegido: <span class="font-mono font-bold" x-text="elegido?.clave"></span>
                        · <span x-text="nombreZona(elegido?.zona)"></span>
                        · giro <span x-text="elegido?.giro"></span>°
                        <span x-show="elegido?.movido" class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-bold text-amber-800">ubicado a mano</span>
                    </p>

                    <svg x-ref="plano" :view-box.camel="viewBox" tabindex="0" role="application" aria-label="Plano del almacén"
                         preserveAspectRatio="xMidYMid meet"
                         class="block h-[60vh] min-h-80 w-full touch-none select-none rounded-2xl bg-slate-50 ring-1 ring-slate-200 focus:outline-none focus:ring-2 focus:ring-primary"
                         @pointerdown="presionar($event)" @pointermove="mover($event)" @pointerup="soltar($event)" @pointercancel="soltar($event)"
                         @wheel.prevent="acercar($event.deltaY > 0 ? 1.1 : 0.9)" @keydown="teclado($event)">
                        {{-- x-for no funciona dentro de un SVG: el contenido lo arma svgPlano. --}}
                        <g x-html="svgPlano"></g>
                    </svg>
                </section>

                <div x-show="vista === '3d'" class="relative h-[65vh] min-h-80 overflow-hidden rounded-2xl ring-1 ring-slate-200">
                    <div x-ref="visor" class="absolute inset-0"></div>
                    <p x-show="mensaje" x-cloak class="absolute inset-x-0 top-0 m-3 rounded-xl bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-900 ring-1 ring-amber-200" x-text="mensaje"></p>
                    <p class="pointer-events-none absolute bottom-2 left-3 text-xs text-slate-500">Las letras y números naranjas muestran cómo se cuentan estantes y posiciones</p>
                </div>
            </div>
        </div>

        <form id="restablecer" method="POST" action="{{ route('admin.almacen.restablecer', $drogueria->codisb) }}"
              onsubmit="return confirm('¿Volver al acomodo recomendado? Se pierde lo configurado.')">
            @csrf
            @method('DELETE')
        </form>
    </div>
</x-layouts.app>
