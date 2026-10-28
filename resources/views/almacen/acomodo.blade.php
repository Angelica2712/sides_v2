@php
    use App\Support\ConfigAlmacen;

    $config = $plano['config'];
    $datos = ['plano' => $plano, 'config' => [
        ...$config,
        'zonas' => (object) $config['zonas'],
        'pasillos' => (object) $config['pasillos'],
        'disposicion' => (object) $config['disposicion'],
    ]];
    $opciones = [
        'numeracion' => ['¿Cómo se numeran los anaqueles en cada pasillo?', ConfigAlmacen::NUMERACIONES],
        'estanteA' => ['¿Dónde está el estante A?', ConfigAlmacen::ESTANTE_A],
        'posicion1' => ['¿Dónde está la posición 1?', ConfigAlmacen::POSICION_1],
    ];
    $columnas = 'grid grid-cols-[3.5rem_1fr_1fr_1fr_2rem] gap-2';
    $campo = 'block w-full rounded-lg border-slate-300 px-2 py-1 text-sm focus:border-primary focus:ring-primary';
    $boton = 'rounded-lg bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50 disabled:opacity-40';
@endphp

<x-layouts.app titulo="Almacén">
    <div class="mx-auto max-w-6xl space-y-4" x-data="almacenConfig(@js($datos))">
        @isset($pestana)
            @include('almacen.partials.pestanas', ['activa' => $pestana])
        @else
            <a href="{{ $volver[0] }}" class="text-sm font-semibold text-primary-ink hover:underline">← {{ $volver[1] }}</a>
        @endisset

        <div>
            <h2 class="text-xl font-extrabold text-slate-900">{{ $titulo }}</h2>
            <p class="max-w-3xl text-sm text-slate-500">
                Las ubicaciones se escriben <span class="font-mono font-bold text-slate-700">{{ \App\Support\UbicacionAlmacen::nombre($plano['config']['formato']) }}</span>.
                Aquí se registran los pasillos y anaqueles del almacén y cómo están numerados, para que el Almacén 3D lo dibuje igual.
            </p>
        </div>

        <div class="grid gap-4 xl:grid-cols-[22rem_1fr]">
            <form method="POST" action="{{ $urls['guardar'] }}" class="space-y-4">
                @csrf
                @method('PUT')

                @if ($errors->any())
                    <div class="rounded-xl bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800 ring-1 ring-rose-200">{{ $errors->first() }}</div>
                @endif

                {{-- Pasillos registrados: es la misma lista que se edita en el 3D y en el plano. --}}
                <fieldset class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                    <legend class="sr-only">Pasillos y anaqueles</legend>
                    <p class="text-sm font-bold text-slate-800" aria-hidden="true">Pasillos y anaqueles</p>
                    <p class="mb-3 text-xs text-slate-500">
                        Cuántos anaqueles hay a la izquierda y a la derecha de quien entra a cada pasillo. Los cambios se ven al momento en el 3D.
                    </p>
                    <datalist id="zonas-almacen">
                        @foreach ($zonas as $codigo => $nombre)
                            <option value="{{ $codigo }}">{{ $nombre }}</option>
                        @endforeach
                    </datalist>
                    <div class="space-y-2">
                        <div x-show="filasPasillos.length" class="{{ $columnas }} text-xs font-semibold text-slate-500" aria-hidden="true">
                            <span>Zona</span><span>Pasillo</span><span>Izq.</span><span>Der.</span><span></span>
                        </div>
                        <template x-for="fila in filasPasillos" :key="fila.clave">
                            <div class="{{ $columnas }} items-center">
                                <input type="text" :value="fila.zona" @change="renombrarPasillo(fila.clave, $event.target.value, fila.pasillo)"
                                       list="zonas-almacen" maxlength="2" aria-label="Zona" autocomplete="off"
                                       class="block w-full rounded-xl border-slate-300 px-2 py-1.5 text-center font-mono text-sm uppercase focus:border-primary focus:ring-primary">
                                <input type="number" :value="fila.pasillo" @change="renombrarPasillo(fila.clave, fila.zona, $event.target.value)"
                                       min="1" max="99" inputmode="numeric" aria-label="Número de pasillo"
                                       class="block w-full rounded-xl border-slate-300 px-2 py-1.5 text-sm focus:border-primary focus:ring-primary">
                                <input type="number" x-model.number="config.pasillos[fila.clave].izquierda" min="0" max="{{ ConfigAlmacen::MAXIMO_POR_LADO }}" inputmode="numeric" aria-label="Anaqueles a la izquierda"
                                       class="block w-full rounded-xl border-slate-300 px-2 py-1.5 text-sm focus:border-primary focus:ring-primary">
                                <input type="number" x-model.number="config.pasillos[fila.clave].derecha" min="0" max="{{ ConfigAlmacen::MAXIMO_POR_LADO }}" inputmode="numeric" aria-label="Anaqueles a la derecha"
                                       class="block w-full rounded-xl border-slate-300 px-2 py-1.5 text-sm focus:border-primary focus:ring-primary">
                                <button type="button" @click="quitarPasillo(fila.clave)" aria-label="Quitar pasillo"
                                        class="size-8 rounded-lg text-lg font-bold text-slate-400 hover:bg-rose-50 hover:text-rose-700">×</button>
                            </div>
                        </template>
                        <button type="button" @click="abrirNuevoPasillo()" class="{{ $boton }}">+ Agregar pasillo</button>
                    </div>
                </fieldset>

                @foreach ($opciones as $nombreCampo => [$pregunta, $valores])
                    <fieldset class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                        <legend class="sr-only">{{ $pregunta }}</legend>
                        <p class="mb-2 text-sm font-bold text-slate-800" aria-hidden="true">{{ $pregunta }}</p>
                        <div class="space-y-2">
                            @foreach ($valores as $valor => [$etiqueta, $ayuda])
                                <label class="flex cursor-pointer gap-3 rounded-xl p-3 ring-1 transition"
                                       :class="config.{{ $nombreCampo }} === '{{ $valor }}' ? 'bg-primary-soft ring-primary' : 'ring-slate-200 hover:bg-slate-50'">
                                    <input type="radio" name="{{ $nombreCampo }}" value="{{ $valor }}" x-model="config.{{ $nombreCampo }}"
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

                <input type="hidden" name="pasillos" :value="JSON.stringify(config.pasillos)">
                <input type="hidden" name="disposicion" :value="JSON.stringify(config.disposicion)">
                <input type="hidden" name="quitados" :value="JSON.stringify(config.quitados)">
                <input type="hidden" name="extras" :value="JSON.stringify(config.extras)">
                <input type="hidden" name="entradas" :value="JSON.stringify(config.entradas)">
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
                        <input type="text" x-model="prueba" @input.debounce.300ms="resaltar()" autocomplete="off" placeholder="{{ \App\Support\UbicacionAlmacen::ejemplo($plano['config']['formato']) }}"
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

                <p x-show="ejemplo" class="flex flex-wrap items-center gap-2 rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-900 ring-1 ring-amber-200">
                    <span>Estos pasillos son <strong>de ejemplo</strong>: este almacén todavía no tiene pasillos registrados.</span>
                    <button type="button" @click="usarEjemplo()" class="font-bold underline">Usar este ejemplo como mi almacén</button>
                    @isset($pestana)
                        <span aria-hidden="true">·</span>
                        <a href="{{ route('almacen.armar') }}" class="font-bold underline">Armarlo con el teléfono</a>
                    @endisset
                </p>

                <div class="flex gap-1 rounded-xl bg-slate-200 p-1" role="tablist" aria-label="Vista">
                    <button type="button" role="tab" :aria-selected="vista === '3d'" @click="vista = '3d'"
                            :class="vista === '3d' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900'"
                            class="flex-1 rounded-lg px-3 py-2 text-sm font-bold">Vista 3D</button>
                    <button type="button" role="tab" :aria-selected="vista === 'plano'" @click="vista = 'plano'; $nextTick(() => $refs.plano.focus())"
                            :class="vista === 'plano' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900'"
                            class="flex-1 rounded-lg px-3 py-2 text-sm font-bold">Plano desde arriba</button>
                </div>

                {{-- Herramientas: sirven igual en el 3D y en el plano. --}}
                <div class="flex flex-wrap items-center gap-2 rounded-2xl bg-white p-3 shadow-sm ring-1 ring-slate-200">
                    <label class="flex items-center gap-2 text-sm font-semibold text-slate-700">
                        <input type="checkbox" x-model="moverPasillo" class="rounded border-slate-300 text-primary focus:ring-primary">
                        Toda la fila
                    </label>
                    <span class="mx-1 h-6 w-px bg-slate-200" aria-hidden="true"></span>
                    <button type="button" @click="girar()" :disabled="!elegido && !entradaElegida" class="{{ $boton }}">Girar 90°</button>
                    <button type="button" @click="abrirRenumerar()" :disabled="!elegido" class="{{ $boton }}">Asignar pasillo</button>
                    <button type="button" @click="restaurar()" :disabled="!elegido" class="{{ $boton }}">Devolver a su lugar</button>
                    <button type="button" @click="borrar()" :disabled="!entradaElegida && !elegido"
                            class="rounded-lg bg-white px-3 py-1.5 text-sm font-semibold text-rose-700 ring-1 ring-rose-200 hover:bg-rose-50 disabled:opacity-40"
                            x-text="entradaElegida ? 'Quitar entrada' : (moverPasillo ? 'Borrar la fila' : 'Borrar anaquel')"></button>
                    <span class="mx-1 h-6 w-px bg-slate-200" aria-hidden="true"></span>
                    <button type="button" @click="abrirNuevoPasillo()"
                            class="rounded-lg bg-primary-soft px-3 py-1.5 text-sm font-bold text-primary-ink ring-1 ring-primary hover:bg-white">+ Pasillo</button>
                    <button type="button" @click="agregarEntrada()" :disabled="config.entradas.length >= {{ ConfigAlmacen::MAXIMO_ENTRADAS }}"
                            class="rounded-lg bg-emerald-50 px-3 py-1.5 text-sm font-bold text-emerald-800 ring-1 ring-emerald-300 hover:bg-emerald-100 disabled:opacity-40">+ Entrada</button>
                    <template x-if="vista === 'plano'">
                        <div class="ml-auto flex items-center gap-2">
                            <button type="button" @click="acercar(0.8)" aria-label="Acercar" class="size-8 rounded-lg bg-white text-lg font-bold text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">+</button>
                            <button type="button" @click="acercar(1.25)" aria-label="Alejar" class="size-8 rounded-lg bg-white text-lg font-bold text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">−</button>
                            <button type="button" @click="encajar()" class="{{ $boton }}">Ver todo</button>
                        </div>
                    </template>
                    <button type="button" @click="restaurarTodo()" x-show="movidos" class="rounded-lg px-3 py-1.5 text-sm font-semibold text-rose-700 hover:bg-rose-50">Acomodar todo automático</button>
                </div>

                <p class="text-xs text-slate-500">
                    Toca un anaquel para elegirlo y ponerle el número de pasillo (con «Toda la fila» se elige la fila de anaqueles que tocaste); arrástralo para ponerlo en su lugar; la raya naranja
                    es su frente. En el plano, las flechas lo mueven 10 cm, la tecla R lo gira y Suprimir lo borra. Los cambios se guardan con «Guardar acomodo».
                </p>

                {{-- Pasillo nuevo --}}
                <template x-if="nuevo">
                    <form @submit.prevent="crearPasillo()" class="space-y-3 rounded-2xl bg-primary-soft p-4 ring-1 ring-primary">
                        <p class="text-sm font-bold text-slate-900">Pasillo nuevo</p>
                        <div class="grid grid-cols-3 gap-2 sm:grid-cols-6">
                            <label class="text-xs font-semibold text-slate-700">Zona
                                <input type="text" x-model="nuevo.zona" list="zonas-almacen" maxlength="2" autocomplete="off" class="{{ $campo }} mt-1 text-center font-mono uppercase">
                            </label>
                            <label class="text-xs font-semibold text-slate-700">Pasillo
                                <input type="number" x-model.number="nuevo.pasillo" min="1" max="99" inputmode="numeric" class="{{ $campo }} mt-1">
                            </label>
                            <label class="text-xs font-semibold text-slate-700">A la izquierda
                                <input type="number" x-model.number="nuevo.izquierda" min="0" max="{{ ConfigAlmacen::MAXIMO_POR_LADO }}" inputmode="numeric" class="{{ $campo }} mt-1">
                            </label>
                            <label class="text-xs font-semibold text-slate-700">A la derecha
                                <input type="number" x-model.number="nuevo.derecha" min="0" max="{{ ConfigAlmacen::MAXIMO_POR_LADO }}" inputmode="numeric" class="{{ $campo }} mt-1">
                            </label>
                            <label class="text-xs font-semibold text-slate-700">Estantes
                                <input type="number" x-model.number="nuevo.estantes" min="1" max="12" inputmode="numeric" class="{{ $campo }} mt-1">
                            </label>
                            <label class="text-xs font-semibold text-slate-700">Huecos
                                <input type="number" x-model.number="nuevo.posiciones" min="1" max="30" inputmode="numeric" class="{{ $campo }} mt-1">
                            </label>
                        </div>
                        <p class="text-xs text-slate-600">Impares a la izquierda y pares a la derecha de quien entra. Después de crearlo queda elegido: arrástralo a su lugar y gíralo si hace falta.</p>
                        <p x-show="ejemplo" class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900 ring-1 ring-amber-200">
                            Ahora ves el almacén de ejemplo: al crear tu primer pasillo deja de verse. Si quieres conservar lo que acomodaste
                            en el ejemplo, cancela y toca «Usar este ejemplo como mi almacén».
                        </p>
                        <div class="flex gap-2">
                            <button type="submit" class="rounded-lg bg-primary px-4 py-1.5 text-sm font-bold text-on-primary hover:bg-primary-dark">Crear pasillo</button>
                            <button type="button" @click="nuevo = null" class="rounded-lg px-3 py-1.5 text-sm font-semibold text-slate-600 hover:bg-white">Cancelar</button>
                        </div>
                    </form>
                </template>

                <div x-show="entradaElegida" x-cloak class="flex flex-wrap items-center gap-2 rounded-xl bg-emerald-50 px-3 py-2 ring-1 ring-emerald-200">
                    <label class="flex flex-1 items-center gap-2 text-sm font-semibold text-emerald-900">
                        Nombre de la entrada
                        <input type="text" maxlength="30" placeholder="Entrada" :value="entradaElegida?.nombre ?? ''"
                               @input="entradaElegida && (entradaElegida.nombre = $event.target.value)"
                               class="min-w-0 flex-1 rounded-lg border-emerald-300 py-1 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                    </label>
                    <span class="text-xs text-emerald-800">giro <span x-text="entradaElegida?.giro"></span>°</span>
                </div>
                <p x-show="elegido" class="text-sm text-slate-700">
                    Elegido: <span class="font-mono font-bold" x-text="moverPasillo ? 'fila del pasillo ' + elegido?.clave.slice(0, 5) : elegido?.clave"></span>
                    · <span x-text="nombreZona(elegido?.zona)"></span>
                    · giro <span x-text="elegido?.giro"></span>°
                    <span x-show="elegido?.movido" class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-bold text-amber-800">ubicado a mano</span>
                    <span x-show="elegidoOcupado" class="ml-1 rounded-full bg-sky-100 px-2 py-0.5 text-xs font-bold text-sky-800">tiene productos</span>
                </p>
                <p x-show="avisoPlano" x-cloak role="status" class="rounded-xl bg-sky-50 px-3 py-2 text-sm font-semibold text-sky-900 ring-1 ring-sky-200" x-text="avisoPlano"></p>
                <p x-show="borrados" x-cloak class="flex flex-wrap items-center gap-2 rounded-xl bg-rose-50 px-3 py-2 text-xs text-rose-900 ring-1 ring-rose-200">
                    <span><span class="font-bold" x-text="borrados"></span> anaqueles borrados del plano.</span>
                    <button type="button" @click="devolverBorrados()" class="font-semibold underline">Volver a ponerlos</button>
                </p>

                <div class="relative">
                {{-- Plano visto desde arriba. --}}
                <svg x-show="vista === 'plano'" x-ref="plano" :view-box.camel="viewBox" tabindex="0" role="application" aria-label="Plano del almacén"
                     preserveAspectRatio="xMidYMid meet"
                     class="block h-[60vh] min-h-80 w-full touch-none select-none rounded-2xl bg-slate-50 ring-1 ring-slate-200 focus:outline-none focus:ring-2 focus:ring-primary"
                     @pointerdown="presionar($event)" @pointermove="mover($event)" @pointerup="soltar($event)" @pointercancel="soltar($event)"
                     @wheel.prevent="acercar($event.deltaY > 0 ? 1.1 : 0.9)" @keydown="teclado($event)">
                    {{-- x-for no funciona dentro de un SVG: el contenido lo arma svgPlano. --}}
                    <g x-html="svgPlano"></g>
                </svg>

                <div x-show="vista === '3d'" class="relative h-[65vh] min-h-80 overflow-hidden rounded-2xl ring-1 ring-slate-200">
                    <div x-ref="visor" class="absolute inset-0"></div>
                    <p x-show="mensaje" x-cloak class="absolute inset-x-0 top-0 m-3 rounded-xl bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-900 ring-1 ring-amber-200" x-text="mensaje"></p>
                    <p class="pointer-events-none absolute bottom-2 left-3 right-40 text-xs text-slate-500">Toca un anaquel para elegirlo · arrastra lo elegido para moverlo · arrastra el resto para desplazarte</p>
                    @include('almacen.partials.controles-3d')
                </div>

                </div>

                {{--
                    Al tocar un anaquel: ponerle el número de pasillo (a su fila o solo a ese anaquel).
                    Va debajo del plano y no encima, para no tapar los anaqueles.
                --}}
                <template x-if="renumero && elegido">
                    <form x-ref="renumero" @submit.prevent="renumerar('pasillo')"
                          class="scroll-mb-4 space-y-2 rounded-2xl bg-white p-3 shadow-sm ring-1 ring-orange-300">
                        <div class="flex items-start justify-between gap-2">
                            <p class="text-sm font-bold text-slate-900">
                                Anaquel <span class="font-mono" x-text="elegido.clave"></span>
                                <span class="block text-xs font-normal text-slate-500">Escribe el número nuevo y toca «Asignar». Sin pasillo, «Solo este anaquel» lo deja en el suyo.</span>
                            </p>
                            <button type="button" @click="renumero = null" aria-label="Cerrar"
                                    class="size-7 shrink-0 rounded-lg text-lg font-bold leading-none text-slate-400 hover:bg-slate-100 hover:text-slate-700">×</button>
                        </div>
                        <div class="grid grid-cols-3 gap-2">
                            <label class="text-xs font-semibold text-slate-700">Zona
                                <input type="text" x-model="renumero.zona" list="zonas-almacen" maxlength="2" autocomplete="off" class="{{ $campo }} mt-1 text-center font-mono uppercase">
                            </label>
                            <label class="text-xs font-semibold text-slate-700">Pasillo
                                <input type="number" x-model.number="renumero.pasillo" min="1" max="99" inputmode="numeric" placeholder="0" class="{{ $campo }} mt-1 text-lg font-bold">
                            </label>
                            <label class="text-xs font-semibold text-slate-700">Anaquel
                                <input type="number" x-model.number="renumero.anaquel" min="1" max="99" inputmode="numeric" placeholder="0" class="{{ $campo }} mt-1 text-lg font-bold">
                            </label>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button type="submit" class="rounded-lg bg-orange-500 px-3 py-1.5 text-sm font-bold text-white hover:bg-orange-600">
                                Asignar a toda la fila
                            </button>
                            <button type="button" @click="renumerar('anaquel')" class="{{ $boton }}">Solo este anaquel</button>
                        </div>
                        <p x-show="avisoPlano" class="rounded-lg bg-sky-50 px-2 py-1 text-xs font-semibold text-sky-900 ring-1 ring-sky-200" x-text="avisoPlano"></p>
                        <p class="text-xs text-slate-500">Los anaqueles se quedan donde están; solo cambia su número. Se guarda con «Guardar acomodo».</p>
                    </form>
                </template>
            </div>
        </div>

        <form id="restablecer" method="POST" action="{{ $urls['restablecer'] }}"
              onsubmit="return confirm('¿Volver al acomodo recomendado? Se pierde lo configurado.')">
            @csrf
            @method('DELETE')
        </form>
    </div>
</x-layouts.app>
