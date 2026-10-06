@php
    $nueva = ! $drogueria->exists;
    $campo = 'mt-1.5 block w-full rounded-xl border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-primary focus:ring-primary';
    $activos = old('modulos', $activos);
    $aModulo = fn ($clave) => ['clave' => $clave, 'nombre' => \App\Support\MenuSides::MODULOS[$clave][0], 'descripcion' => \App\Support\MenuSides::MODULOS[$clave][3]];
    $grupos = [
        'Módulos básicos' => ['ayuda' => 'Encendidos por defecto. Apágalos si esta droguería no debe usarlos.', 'modulos' => array_map($aModulo, \App\Support\MenuSides::BASICOS)],
        'Módulos opcionales' => ['ayuda' => 'Apagados por defecto. Enciéndelos cuando la droguería los vaya a usar.', 'modulos' => array_map($aModulo, \App\Support\MenuSides::OPCIONALES)],
    ];
@endphp

<x-layouts.app :titulo="$nueva ? 'Nueva droguería' : 'Configurar droguería'">
    <div class="mx-auto max-w-3xl space-y-4">
        <a href="{{ route('admin.index') }}" class="text-sm font-semibold text-primary-ink hover:underline">← Volver a droguerías</a>

        @unless ($nueva)
            @php
                $credenciales = session('credenciales');
                $enlaceEntrada = route('login.drogueria', $drogueria->codisb);
            @endphp
            @if ($credenciales)
                <div role="status" class="space-y-2 rounded-2xl bg-amber-50 p-4 text-sm ring-1 ring-amber-300">
                    <p class="font-bold text-amber-900">Copia estos datos ahora: la contraseña no se vuelve a mostrar.</p>
                    <p class="text-amber-800">Entrégaselos al encargado. Desde Usuarios podrá cambiar su contraseña y crear a los demás usuarios de la droguería.</p>
                    <dl class="grid gap-2 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <dt class="font-semibold text-amber-900">Enlace para entrar</dt>
                            <dd><input type="text" readonly value="{{ $enlaceEntrada }}" onclick="this.select()" aria-label="Enlace para entrar" class="mt-1 block w-full rounded-xl border-amber-300 bg-white px-3.5 py-2.5 font-mono text-sm"></dd>
                        </div>
                        <div>
                            <dt class="font-semibold text-amber-900">Correo</dt>
                            <dd><input type="text" readonly value="{{ $credenciales['correo'] }}" onclick="this.select()" aria-label="Correo" class="mt-1 block w-full rounded-xl border-amber-300 bg-white px-3.5 py-2.5 font-mono text-sm"></dd>
                        </div>
                        <div>
                            <dt class="font-semibold text-amber-900">Contraseña</dt>
                            <dd><input type="text" readonly value="{{ $credenciales['clave'] }}" onclick="this.select()" aria-label="Contraseña" class="mt-1 block w-full rounded-xl border-amber-300 bg-white px-3.5 py-2.5 font-mono text-sm"></dd>
                        </div>
                    </dl>
                </div>
            @endif

            <section class="space-y-3 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
                <div>
                    <h3 class="text-lg font-extrabold text-slate-900">Usuarios de la droguería</h3>
                    <p class="text-sm text-slate-500">El encargado entra con todos los permisos de su droguería y desde Usuarios crea a los demás.</p>
                </div>

                {{-- Por este enlace el login sale con el logo de la droguería (después el equipo lo recuerda). --}}
                <div x-data="{ copiado: false }" class="rounded-xl bg-slate-50 p-3 ring-1 ring-slate-200">
                    <label for="enlace_entrada" class="block text-sm font-semibold text-slate-700">Enlace para entrar</label>
                    <div class="mt-1 flex gap-2">
                        <input id="enlace_entrada" type="text" readonly value="{{ $enlaceEntrada }}" onclick="this.select()"
                               class="block w-full min-w-0 rounded-xl border-slate-300 bg-white px-3 py-2 font-mono text-sm">
                        <button type="button" @click="navigator.clipboard.writeText(@js($enlaceEntrada)); copiado = true; setTimeout(() => copiado = false, 2000)"
                                class="shrink-0 rounded-xl px-3 py-2 text-sm font-semibold text-slate-700 ring-1 ring-slate-300 hover:bg-white"
                                x-text="copiado ? 'Copiado' : 'Copiar'">Copiar</button>
                    </div>
                    <p class="mt-1 text-xs text-slate-500">Con este enlace el inicio de sesión muestra el logo de la droguería. Después de entrar una vez, ese equipo lo recuerda.</p>
                </div>

                @forelse ($usuarios as $usuarioDrogueria)
                    <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl p-3 ring-1 ring-slate-200">
                        <div class="min-w-0">
                            <p class="truncate font-bold text-slate-900">
                                {{ $usuarioDrogueria->name }}
                                @if ($usuarioDrogueria->activarUsuario)
                                    <span class="ms-1 rounded-full bg-primary-soft px-2 py-0.5 text-xs font-bold text-primary-ink">Crea usuarios</span>
                                @endif
                                @if ($usuarioDrogueria->estado !== 'ACTIVO')
                                    <span class="ms-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-bold text-slate-500">Inactivo</span>
                                @endif
                            </p>
                            <p class="truncate text-sm text-slate-500">{{ $usuarioDrogueria->email }}</p>
                        </div>
                        @unless ($usuarioDrogueria->esAdmin)
                            @php $erroresClave = $errors->getBag('clave'.$usuarioDrogueria->id); @endphp
                            <div x-data="{ abierto: @js($erroresClave->any()) }" class="w-full sm:w-auto">
                                <button type="button" x-show="!abierto" @click="abierto = true; $nextTick(() => $refs.clave.focus())"
                                        class="rounded-xl px-3 py-2 text-sm font-semibold text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">Cambiar contraseña</button>
                                <form x-show="abierto" method="POST" action="{{ route('admin.clave', [$drogueria->codisb, $usuarioDrogueria->id]) }}"
                                      onsubmit="return confirm('La contraseña anterior deja de servir. ¿Continuar?')" class="space-y-1">
                                    @csrf
                                    <div class="flex flex-wrap items-center gap-2">
                                        <input x-ref="clave" name="password" type="text" minlength="6" maxlength="100" autocomplete="off" spellcheck="false"
                                               placeholder="Vacío = automática" aria-label="Contraseña nueva para {{ $usuarioDrogueria->name }}"
                                               class="w-48 rounded-xl border-slate-300 px-3 py-2 font-mono text-sm focus:border-primary focus:ring-primary">
                                        <button type="submit" class="rounded-xl bg-primary px-3 py-2 text-sm font-bold text-on-primary hover:bg-primary-dark">Guardar</button>
                                        <button type="button" @click="abierto = false" class="px-2 py-2 text-sm font-semibold text-slate-500 hover:text-slate-700">Cancelar</button>
                                    </div>
                                    @if ($erroresClave->has('password'))
                                        <p class="text-sm font-semibold text-rose-600">{{ $erroresClave->first('password') }}</p>
                                    @endif
                                </form>
                            </div>
                        @endunless
                    </div>
                @empty
                    <p class="rounded-xl bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-900 ring-1 ring-amber-200">Esta droguería todavía no tiene usuarios: nadie puede entrar. Crea su encargado.</p>
                @endforelse

                <form method="POST" action="{{ route('admin.encargado', $drogueria->codisb) }}" class="grid gap-3 border-t border-slate-200 pt-4 sm:grid-cols-[1fr_1fr_1fr_auto] sm:items-end">
                    @csrf
                    <div>
                        <label for="encargado_nombre" class="block text-sm font-semibold text-slate-700">Nombre del encargado</label>
                        <input id="encargado_nombre" name="name" type="text" maxlength="255" required value="{{ old('name') }}" class="{{ $campo }}">
                    </div>
                    <div>
                        <label for="encargado_correo" class="block text-sm font-semibold text-slate-700">Correo para entrar</label>
                        <input id="encargado_correo" name="email" type="email" maxlength="255" required value="{{ old('email') }}" class="{{ $campo }}">
                    </div>
                    <div>
                        <label for="encargado_clave" class="block text-sm font-semibold text-slate-700">Contraseña <span class="font-normal text-slate-400">(vacía = automática)</span></label>
                        <input id="encargado_clave" name="password" type="text" minlength="6" maxlength="100" autocomplete="off" spellcheck="false"
                               placeholder="Automática" class="{{ $campo }} font-mono">
                    </div>
                    <button type="submit" class="rounded-xl bg-primary px-4 py-2.5 text-sm font-bold text-on-primary hover:bg-primary-dark">Crear encargado</button>
                </form>
            </section>
        @endunless

        <form method="POST" action="{{ $nueva ? route('admin.store') : route('admin.update', $drogueria->codisb) }}"
              enctype="multipart/form-data" class="space-y-4" x-data="{ batch: @js(in_array('batch', $activos, true)), etiquetas: @js(in_array('etiquetas', $activos, true)) }">
            @csrf
            @unless ($nueva) @method('PUT') @endunless

            @if ($errors->any())
                <div role="alert" class="rounded-xl bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800 ring-1 ring-rose-200">
                    @foreach ($errors->all() as $error)
                        <p>{{ $error }}</p>
                    @endforeach
                </div>
            @endif

            <section class="space-y-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
                <div>
                    <h2 class="text-lg font-extrabold text-slate-900">{{ $nueva ? 'Nueva droguería' : $drogueria->nombre }}</h2>
                    <p class="text-sm text-slate-500">Estos datos y el logo salen en el inicio de sesión, el encabezado de SIDES, las etiquetas, el ticket y las guías. Solo se cambian desde aquí.</p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="codisb" class="block text-sm font-semibold text-slate-700">Código</label>
                        <input id="codisb" name="codisb" type="text" maxlength="20" value="{{ old('codisb', $drogueria->codisb) }}"
                               @if ($nueva) required @else readonly @endif
                               class="{{ $campo }} read-only:bg-slate-100">
                        @if ($nueva)
                            <p class="mt-1 text-xs text-slate-500">El mismo código de sucursal que usa SEPED (codisb).</p>
                        @endif
                    </div>
                    <div>
                        <label for="nomcorto" class="block text-sm font-semibold text-slate-700">Nombre corto</label>
                        <input id="nomcorto" name="nomcorto" type="text" maxlength="20" value="{{ old('nomcorto', $drogueria->nomcorto) }}" class="{{ $campo }}">
                        <p class="mt-1 text-xs text-slate-500">Se ve en el menú lateral.</p>
                    </div>
                    <div class="sm:col-span-2">
                        <label for="nombre" class="block text-sm font-semibold text-slate-700">Nombre</label>
                        <input id="nombre" name="nombre" type="text" maxlength="150" required value="{{ old('nombre', $drogueria->nombre) }}" class="{{ $campo }}">
                    </div>
                    @foreach ([
                        'rif' => ['RIF', 20],
                        'localidad' => ['Localidad', 100],
                        'contacto' => ['Contacto', 50],
                        'telefono' => ['Teléfono', 50],
                    ] as $nombreCampo => [$etiqueta, $largo])
                        <div>
                            <label for="{{ $nombreCampo }}" class="block text-sm font-semibold text-slate-700">{{ $etiqueta }}</label>
                            <input id="{{ $nombreCampo }}" name="{{ $nombreCampo }}" type="text" maxlength="{{ $largo }}" value="{{ old($nombreCampo, $drogueria->{$nombreCampo}) }}" class="{{ $campo }}">
                        </div>
                    @endforeach
                    <div class="sm:col-span-2">
                        <label for="direccion" class="block text-sm font-semibold text-slate-700">Dirección</label>
                        <input id="direccion" name="direccion" type="text" maxlength="150" value="{{ old('direccion', $drogueria->direccion) }}" class="{{ $campo }}">
                    </div>
                </div>

                {{-- Logo: vista previa del archivo elegido antes de guardar. --}}
                <div class="rounded-xl p-4 ring-1 ring-slate-200"
                     x-data="{ vista: @js($drogueria->urlLogo()), quitar: false, forma: @js(old('logoForma', $drogueria->logoForma ?: 'cuadro')), elegir(e) { const f = e.target.files[0]; this.quitar = false; this.vista = f ? URL.createObjectURL(f) : @js($drogueria->urlLogo()); } }">
                    <p class="text-sm font-semibold text-slate-700">Logo</p>
                    <div class="mt-2 flex flex-wrap items-center gap-4">
                        {{-- La vista previa toma la forma elegida: así se ve en el encabezado y en el inicio de sesión. --}}
                        <div :class="forma === 'circulo' && vista && !quitar ? 'size-24 rounded-full' : 'h-24 w-40 rounded-xl p-2'"
                             class="flex shrink-0 items-center justify-center overflow-hidden bg-slate-50 ring-1 ring-slate-200">
                            <template x-if="vista && !quitar">
                                <img :src="vista" alt="Logo de la droguería" :class="forma === 'circulo' ? 'size-full object-cover' : 'max-h-full max-w-full object-contain'">
                            </template>
                            <span x-show="!vista || quitar" class="text-center text-xs text-slate-400">Sin logo: se usa el de SIDES</span>
                        </div>
                        <div class="min-w-0 space-y-2 text-sm">
                            <input id="logo" name="logo" type="file" accept="image/png,image/jpeg,image/webp" @change="elegir($event)"
                                   class="block w-full text-sm text-slate-600 file:mr-3 file:rounded-xl file:border-0 file:bg-primary-soft file:px-4 file:py-2 file:font-bold file:text-primary-ink hover:file:bg-primary hover:file:text-white">
                            <p class="text-xs text-slate-500">PNG, JPG o WEBP de hasta 2 MB. Mejor horizontal y con fondo blanco o transparente: las etiquetas se imprimen en blanco y negro.</p>
                            <fieldset>
                                <legend class="font-semibold text-slate-700">Forma del logo</legend>
                                <div class="mt-1 flex flex-wrap gap-x-5 gap-y-1">
                                    @foreach (\App\Models\Sides\SidesCfg::FORMAS_LOGO as $valor => $etiqueta)
                                        <label class="flex cursor-pointer items-center gap-2">
                                            <input type="radio" name="logoForma" value="{{ $valor }}" x-model="forma" class="size-4 border-slate-300 text-primary focus:ring-primary">
                                            <span class="text-slate-700">{{ $etiqueta }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                <p class="mt-1 text-xs text-slate-500">Círculo para logos redondos; cuadro para los horizontales o con texto. Cambia cómo se ve en el encabezado, el inicio de sesión y la pestaña del navegador, no en las etiquetas ni las guías.</p>
                                @error('logoForma') <p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p> @enderror
                            </fieldset>
                            @if ($drogueria->logo)
                                <label class="flex cursor-pointer items-center gap-2">
                                    <input type="checkbox" name="quitarLogo" value="1" x-model="quitar" class="size-4 rounded border-slate-300 text-rose-600 focus:ring-rose-500">
                                    <span class="font-semibold text-slate-700">Quitar el logo</span>
                                </label>
                            @endif
                        </div>
                    </div>
                </div>
            </section>

            <section class="space-y-3 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
                <div>
                    <h3 class="text-lg font-extrabold text-slate-900">Módulos</h3>
                    <p class="text-sm text-slate-500">Lo que esta droguería puede usar. Un módulo apagado desaparece del menú de todos sus usuarios, sin importar sus permisos.</p>
                </div>

                <label class="flex cursor-pointer items-start gap-3 rounded-xl p-3 ring-1 ring-slate-200 has-checked:bg-primary-soft has-checked:ring-primary">
                    <input type="hidden" name="activarPacking" value="0">
                    <input type="checkbox" name="activarPacking" value="1" @checked(old('activarPacking', $drogueria->activarPacking))
                           class="mt-0.5 size-5 rounded border-slate-300 text-primary-ink focus:ring-primary">
                    <span>
                        <span class="block font-bold text-slate-900">Packing</span>
                        <span class="block text-sm text-slate-500">Verificación y embalaje antes de facturar. Sin packing, el pedido pasa a facturar al terminar el picking.</span>
                    </span>
                </label>

                @foreach ($grupos as $tituloGrupo => $grupo)
                <div class="pt-2">
                    <h4 class="text-xs font-bold uppercase tracking-wide text-slate-500">{{ $tituloGrupo }}</h4>
                    <p class="text-xs text-slate-500">{{ $grupo['ayuda'] }}</p>
                </div>
                @foreach ($grupo['modulos'] as $modulo)
                    <div class="rounded-xl ring-1 ring-slate-200 has-[input[name='modulos[]']:checked]:bg-primary-soft has-[input[name='modulos[]']:checked]:ring-primary">
                        <label class="flex cursor-pointer items-start gap-3 p-3">
                            <input type="checkbox" name="modulos[]" value="{{ $modulo['clave'] }}" @checked(in_array($modulo['clave'], $activos, true))
                                   @if ($modulo['clave'] === 'batch') x-model="batch" @elseif ($modulo['clave'] === 'etiquetas') x-model="etiquetas" @endif
                                   class="mt-0.5 size-5 rounded border-slate-300 text-primary-ink focus:ring-primary">
                            <span>
                                <span class="block font-bold text-slate-900">{{ $modulo['nombre'] }}</span>
                                <span class="block text-sm text-slate-500">{{ $modulo['descripcion'] }}</span>
                            </span>
                        </label>

                        @if ($modulo['clave'] === 'batch')
                            <label x-show="batch" x-cloak class="flex cursor-pointer items-start gap-3 border-t border-slate-200 px-3 py-2.5 pl-11">
                                <input type="hidden" name="procAlcabalaPicking" value="0">
                                <input type="checkbox" name="procAlcabalaPicking" value="1" @checked(old('procAlcabalaPicking', $drogueria->procAlcabalaPicking))
                                       class="mt-0.5 size-4 rounded border-slate-300 text-primary-ink focus:ring-primary">
                                <span class="text-sm">
                                    <span class="font-semibold text-slate-800">Los pedidos nuevos llegan en espera</span>
                                    <span class="block text-slate-500">Un encargado los agrupa en lotes o los libera al picking normal. Si está apagado, se agrupan los pedidos recibidos que nadie tomó.</span>
                                </span>
                            </label>
                        @elseif ($modulo['clave'] === 'etiquetas')
                            <div x-show="etiquetas" x-cloak class="space-y-2.5 border-t border-slate-200 px-3 py-3 pl-11 text-sm">
                                <div>
                                    <label for="formatoPersEtiq" class="font-semibold text-slate-800">Tamaño de la etiqueta</label>
                                    <select id="formatoPersEtiq" name="formatoPersEtiq"
                                            class="mt-1 block w-full max-w-xs rounded-xl border-slate-300 py-2 text-sm shadow-sm focus:border-primary focus:ring-primary">
                                        @foreach (\App\Support\FormatosEtiqueta::FORMATOS as $claveFormato => [$nombreFormato])
                                            <option value="{{ $claveFormato }}" @selected(old('formatoPersEtiq', \App\Support\FormatosEtiqueta::clave($drogueria->formatoPersEtiq)) === $claveFormato)>{{ $nombreFormato }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                @foreach ([
                                    'activar_etiqueta_packing' => ['Imprimir las etiquetas al terminar Packing', 'Al enviar el pedido a facturar se abren sus etiquetas listas para imprimir.'],
                                    'mostrarEntrega' => ['Dirección de entrega en la etiqueta', 'Agrega la dirección de entrega del pedido debajo del nombre del cliente.'],
                                    'activarImpTicket' => ['Ticket de despacho', 'Botón para imprimir un ticket con los productos y unidades despachadas.'],
                                ] as $campo => [$titulo, $ayuda])
                                    <label class="flex cursor-pointer items-start gap-3">
                                        <input type="hidden" name="{{ $campo }}" value="0">
                                        <input type="checkbox" name="{{ $campo }}" value="1" @checked(old($campo, $drogueria->{$campo}))
                                               class="mt-0.5 size-4 rounded border-slate-300 text-primary-ink focus:ring-primary">
                                        <span>
                                            <span class="font-semibold text-slate-800">{{ $titulo }}</span>
                                            <span class="block text-slate-500">{{ $ayuda }}</span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
                @endforeach
            </section>

            <div class="flex justify-end gap-2">
                <a href="{{ route('admin.index') }}" class="rounded-xl px-4 py-2.5 text-sm font-semibold text-slate-600 hover:bg-slate-100">Cancelar</a>
                <button type="submit" class="rounded-xl bg-primary px-5 py-2.5 text-sm font-bold text-on-primary hover:bg-primary-dark">{{ $nueva ? 'Crear droguería' : 'Guardar cambios' }}</button>
            </div>
        </form>
    </div>
</x-layouts.app>
