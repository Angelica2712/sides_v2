@php
    use App\Http\Controllers\ConfiguracionController;
    use App\Support\MenuSides;

    $campo = 'mt-1.5 block w-full rounded-xl border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-primary focus:ring-primary';
    $valor = fn (string $nombre) => old($nombre, $cfg->{$nombre});
    // Un checkbox sin marcar no viaja en el formulario: tras un error de validación, ausente = apagado.
    $marcado = fn (string $nombre) => session()->hasOldInput() ? (bool) old($nombre) : (bool) $cfg->{$nombre};
    $activos = collect(['packing' => (bool) $cfg->activarPacking])
        ->merge(collect(MenuSides::OPCIONALES)->mapWithKeys(fn ($clave) => [$clave => in_array($clave, $modulos, true)]));

    $secciones = [
        'Monitor' => [
            'MostrarTituloMonitor' => ['Mostrar el título', 'Nombre de la sucursal arriba del monitor.'],
            'activarVerOperadorMonitor' => ['Mostrar el operador', 'Quién está trabajando cada pedido.'],
            'mostrarObsMonitor' => ['Mostrar la observación', 'La observación que trae el pedido desde SEPED.'],
            'mostrarTranMonitor' => ['Mostrar el transporte', 'Transporte o ruta de entrega del pedido.'],
        ],
        'Picking' => [
            'activarValPicking' => ['Pedir clave de supervisor al escribir cantidades a mano', 'Sin la clave, el operario solo puede registrar productos escaneando.'],
            'mostrarExiRealPick' => ['Pedir la existencia real', 'El operario anota cuántas unidades quedaron en la ubicación.'],
            'mostrarDepPiking' => ['Mostrar el depósito', 'Depósito del producto en letra grande junto a la descripción.'],
        ],
        'Packing' => [
            'activarValPacking' => ['Pedir clave de supervisor para ajustar cantidades', 'Ajustar a mano lo despachado requiere la clave.'],
            'activar_separador_automatico' => ['Separador automático de cestas', 'Al escribir cestas, agrega la coma para la siguiente después de 1 segundo.'],
        ],
    ];
    if (in_array('rutas', $modulos, true)) {
        $secciones['Rutas'] = [
            'activarSincronizacionRutas' => ['Sincronizar las rutas con SEPED', 'Cada hora se crean las rutas que SEPED asigna a los clientes y se agregan los clientes nuevos. Mientras esté activa, las rutas no se crean a mano.'],
        ];
    }
@endphp

<x-layouts.app titulo="Configuración">
    <div class="mx-auto max-w-3xl space-y-4">
        <div>
            <h2 class="text-xl font-extrabold text-slate-900">Configuración</h2>
            <p class="text-sm text-slate-500">Parámetros de la sucursal {{ $cfg->codisb }}. Se aplican a todos sus usuarios.</p>
        </div>

        <form method="POST" action="{{ route('configuracion.update') }}" class="space-y-4"
              x-data="{ clavePicking: @js($marcado('activarValPicking')), clavePacking: @js($marcado('activarValPacking')) }">
            @csrf
            @method('PUT')

            @if ($errors->any())
                <div role="alert" class="rounded-xl bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800 ring-1 ring-rose-200">
                    @foreach ($errors->all() as $error)
                        <p>{{ $error }}</p>
                    @endforeach
                </div>
            @endif

            <section class="space-y-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
                <h3 class="text-lg font-extrabold text-slate-900">Datos de la sucursal</h3>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label for="nombre" class="block text-sm font-semibold text-slate-700">Nombre</label>
                        <input id="nombre" name="nombre" type="text" maxlength="150" required value="{{ $valor('nombre') }}" class="{{ $campo }}">
                    </div>
                    @foreach ([
                        'nomcorto' => ['Nombre corto', 20],
                        'rif' => ['RIF', 20],
                        'contacto' => ['Contacto', 50],
                        'telefono' => ['Teléfono', 50],
                        'localidad' => ['Localidad', 100],
                        'direccion' => ['Dirección', 150],
                    ] as $nombre => [$etiqueta, $largo])
                        <div>
                            <label for="{{ $nombre }}" class="block text-sm font-semibold text-slate-700">{{ $etiqueta }}</label>
                            <input id="{{ $nombre }}" name="{{ $nombre }}" type="text" maxlength="{{ $largo }}" value="{{ $valor($nombre) }}" class="{{ $campo }}">
                        </div>
                    @endforeach
                </div>
            </section>

            @foreach ($secciones as $titulo => $interruptores)
                <section class="space-y-3 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
                    <h3 class="text-lg font-extrabold text-slate-900">{{ $titulo }}</h3>

                    @if ($titulo === 'Monitor')
                        <div>
                            <label for="TamLetraMonitor" class="block text-sm font-semibold text-slate-700">Tamaño de la letra</label>
                            <select id="TamLetraMonitor" name="TamLetraMonitor" class="{{ $campo }} max-w-xs">
                                @foreach (ConfiguracionController::TAMANOS_LETRA as $tamano)
                                    <option value="{{ $tamano }}" @selected((int) $valor('TamLetraMonitor') === $tamano)>{{ $tamano }} px</option>
                                @endforeach
                            </select>
                        </div>
                    @elseif ($titulo === 'Picking')
                        <div>
                            <label for="ordenPedSides" class="block text-sm font-semibold text-slate-700">Orden de los productos</label>
                            <select id="ordenPedSides" name="ordenPedSides" class="{{ $campo }} max-w-xs">
                                @php $orden = array_key_exists($valor('ordenPedSides'), ConfiguracionController::ORDENES) ? $valor('ordenPedSides') : 'ORIGINAL'; @endphp
                                @foreach (ConfiguracionController::ORDENES as $clave => $etiqueta)
                                    <option value="{{ $clave }}" @selected($orden === $clave)>{{ $etiqueta }}</option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-xs text-slate-500">Se usa en Picking, Packing y Batch Picking.</p>
                        </div>
                    @endif

                    @foreach ($interruptores as $nombre => [$etiqueta, $ayuda])
                        <label class="flex cursor-pointer items-start gap-3 rounded-xl p-3 ring-1 ring-slate-200 has-checked:bg-primary-soft has-checked:ring-primary">
                            <input type="checkbox" name="{{ $nombre }}" value="1" @checked($marcado($nombre))
                                   @if ($nombre === 'activarValPicking') x-model="clavePicking" @elseif ($nombre === 'activarValPacking') x-model="clavePacking" @endif
                                   class="mt-0.5 size-5 rounded border-slate-300 text-primary focus:ring-primary">
                            <span>
                                <span class="block font-bold text-slate-900">{{ $etiqueta }}</span>
                                <span class="block text-sm text-slate-500">{{ $ayuda }}</span>
                            </span>
                        </label>
                    @endforeach

                    @if ($titulo === 'Packing')
                        {{-- Una sola clave de supervisor para Picking y Packing, como en el legacy. --}}
                        <div x-show="clavePicking || clavePacking" x-cloak>
                            <label for="claveValPicking" class="block text-sm font-semibold text-slate-700">Clave de supervisor</label>
                            <input id="claveValPicking" name="claveValPicking" type="text" minlength="4" maxlength="20" autocomplete="off"
                                   value="{{ $valor('claveValPicking') }}" class="{{ $campo }} max-w-xs"
                                   x-bind:required="clavePicking || clavePacking">
                            <p class="mt-1 text-xs text-slate-500">La misma clave sirve en Picking y en Packing.</p>
                        </div>
                    @endif
                </section>
            @endforeach

            <section class="space-y-3 rounded-2xl bg-slate-50 p-5 ring-1 ring-slate-200">
                <div>
                    <h3 class="text-lg font-extrabold text-slate-900">Módulos</h3>
                    <p class="text-sm text-slate-500">Los activa el administrador de SIDES, junto con las opciones de etiquetas.</p>
                </div>
                <ul class="flex flex-wrap gap-2">
                    @foreach ($activos as $clave => $activo)
                        <li class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-bold ring-1 {{ $activo ? 'bg-emerald-50 text-emerald-800 ring-emerald-200' : 'bg-white text-slate-500 ring-slate-300' }}">
                            {{ MenuSides::MODULOS[$clave][0] }}
                            <span class="font-semibold">{{ $activo ? 'activo' : 'apagado' }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>

            <div class="flex justify-end gap-2">
                <a href="{{ route('home') }}" class="rounded-xl px-4 py-2.5 text-sm font-semibold text-slate-600 hover:bg-slate-100">Cancelar</a>
                <button type="submit" class="rounded-xl bg-primary px-5 py-2.5 text-sm font-bold text-white hover:bg-primary-dark">Guardar configuración</button>
            </div>
        </form>
    </div>
</x-layouts.app>
