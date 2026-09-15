@php
    $nueva = ! $drogueria->exists;
    $activos = old('modulos', $activos);
    $opcionales = collect(\App\Support\MenuSides::OPCIONALES)
        ->map(fn ($clave) => ['clave' => $clave, 'nombre' => \App\Support\MenuSides::MODULOS[$clave][0], 'descripcion' => \App\Support\MenuSides::MODULOS[$clave][3]]);
@endphp

<x-layouts.app :titulo="$nueva ? 'Nueva droguería' : 'Configurar droguería'">
    <div class="mx-auto max-w-3xl space-y-4">
        <a href="{{ route('admin.index') }}" class="text-sm font-semibold text-primary hover:underline">← Volver a droguerías</a>

        <form method="POST" action="{{ $nueva ? route('admin.store') : route('admin.update', $drogueria->codisb) }}"
              class="space-y-4" x-data="{ batch: @js(in_array('batch', $activos, true)), etiquetas: @js(in_array('etiquetas', $activos, true)) }">
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
                <h2 class="text-lg font-extrabold text-slate-900">{{ $nueva ? 'Nueva droguería' : $drogueria->nombre }}</h2>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="codisb" class="block text-sm font-semibold text-slate-700">Código</label>
                        <input id="codisb" name="codisb" type="text" maxlength="20" value="{{ old('codisb', $drogueria->codisb) }}"
                               @if ($nueva) required @else readonly @endif
                               class="mt-1.5 block w-full rounded-xl border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-primary focus:ring-primary read-only:bg-slate-100">
                        @if ($nueva)
                            <p class="mt-1 text-xs text-slate-500">El mismo código de sucursal que usa SEPED (codisb).</p>
                        @endif
                    </div>
                    <div>
                        <label for="nomcorto" class="block text-sm font-semibold text-slate-700">Nombre corto</label>
                        <input id="nomcorto" name="nomcorto" type="text" maxlength="20" value="{{ old('nomcorto', $drogueria->nomcorto) }}"
                               class="mt-1.5 block w-full rounded-xl border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-primary focus:ring-primary">
                    </div>
                    <div class="sm:col-span-2">
                        <label for="nombre" class="block text-sm font-semibold text-slate-700">Nombre</label>
                        <input id="nombre" name="nombre" type="text" maxlength="150" required value="{{ old('nombre', $drogueria->nombre) }}"
                               class="mt-1.5 block w-full rounded-xl border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-primary focus:ring-primary">
                    </div>
                </div>
            </section>

            <section class="space-y-3 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
                <div>
                    <h3 class="text-lg font-extrabold text-slate-900">Módulos</h3>
                    <p class="text-sm text-slate-500">Monitor, Picking, Pedidos, Resumen, Usuarios, Informes y Configuración están siempre activos.</p>
                </div>

                <label class="flex cursor-pointer items-start gap-3 rounded-xl p-3 ring-1 ring-slate-200 has-checked:bg-primary-soft has-checked:ring-primary">
                    <input type="hidden" name="activarPacking" value="0">
                    <input type="checkbox" name="activarPacking" value="1" @checked(old('activarPacking', $drogueria->activarPacking))
                           class="mt-0.5 size-5 rounded border-slate-300 text-primary focus:ring-primary">
                    <span>
                        <span class="block font-bold text-slate-900">Packing</span>
                        <span class="block text-sm text-slate-500">Verificación y embalaje antes de facturar. Sin packing, el pedido pasa a facturar al terminar el picking.</span>
                    </span>
                </label>

                @foreach ($opcionales as $modulo)
                    <div class="rounded-xl ring-1 ring-slate-200 has-[input[name='modulos[]']:checked]:bg-primary-soft has-[input[name='modulos[]']:checked]:ring-primary">
                        <label class="flex cursor-pointer items-start gap-3 p-3">
                            <input type="checkbox" name="modulos[]" value="{{ $modulo['clave'] }}" @checked(in_array($modulo['clave'], $activos, true))
                                   @if ($modulo['clave'] === 'batch') x-model="batch" @elseif ($modulo['clave'] === 'etiquetas') x-model="etiquetas" @endif
                                   class="mt-0.5 size-5 rounded border-slate-300 text-primary focus:ring-primary">
                            <span>
                                <span class="block font-bold text-slate-900">{{ $modulo['nombre'] }}</span>
                                <span class="block text-sm text-slate-500">{{ $modulo['descripcion'] }}</span>
                            </span>
                        </label>

                        @if ($modulo['clave'] === 'batch')
                            <label x-show="batch" x-cloak class="flex cursor-pointer items-start gap-3 border-t border-slate-200 px-3 py-2.5 pl-11">
                                <input type="hidden" name="procAlcabalaPicking" value="0">
                                <input type="checkbox" name="procAlcabalaPicking" value="1" @checked(old('procAlcabalaPicking', $drogueria->procAlcabalaPicking))
                                       class="mt-0.5 size-4 rounded border-slate-300 text-primary focus:ring-primary">
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
                                               class="mt-0.5 size-4 rounded border-slate-300 text-primary focus:ring-primary">
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
            </section>

            <div class="flex justify-end gap-2">
                <a href="{{ route('admin.index') }}" class="rounded-xl px-4 py-2.5 text-sm font-semibold text-slate-600 hover:bg-slate-100">Cancelar</a>
                <button type="submit" class="rounded-xl bg-primary px-5 py-2.5 text-sm font-bold text-white hover:bg-primary-dark">{{ $nueva ? 'Crear droguería' : 'Guardar cambios' }}</button>
            </div>
        </form>
    </div>
</x-layouts.app>
