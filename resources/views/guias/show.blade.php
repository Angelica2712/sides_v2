@php
    use App\Support\FechaSeped;
    use Illuminate\Support\Carbon;

    $fecha = fn ($valor, $formato = 'd-m-y H:i') => $valor ? Carbon::parse($valor)->format($formato) : null;
    $bultos = $clientes->sum('totalBultos');
    $cargados = $clientes->sum(fn ($c) => $c->pedidos->sum(fn ($p) => $p->bultos->whereNotNull('feccargado')->count()));
    $entregados = $clientes->sum(fn ($c) => $c->pedidos->sum(fn ($p) => $p->bultos->whereNotNull('fecentregado')->count()));
    $porcentaje = fn ($parte) => $bultos > 0 ? round($parte * 100 / $bultos) : 0;
    $botonChico = 'rounded-lg px-2.5 py-1 text-xs font-bold';
@endphp

<x-layouts.app :titulo="'Guía #'.$guia->id">
    <div class="mx-auto max-w-7xl space-y-4">
        <a href="{{ route('guias.index') }}" class="text-sm font-semibold text-primary hover:underline">← Volver a guías</a>

        <section class="space-y-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="text-2xl font-black text-slate-900">Guía #{{ $guia->id }}</h2>
                        <x-estado-guia :estado="$guia->estado" />
                    </div>
                    <p class="text-sm text-slate-500">Ruta <span class="font-semibold text-slate-700">{{ $guia->ruta }}</span> · {{ $fecha($guia->fecha, 'd-m-Y H:i') }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('guias.imprimir', $guia->id) }}" class="rounded-xl bg-white px-4 py-2 text-sm font-bold text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">Imprimir</a>
                    <a href="{{ route('guias.excel', $guia->id) }}" class="rounded-xl bg-white px-4 py-2 text-sm font-bold text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">Excel</a>
                    <a href="{{ route('guias.edit', $guia->id) }}" class="rounded-xl bg-slate-800 px-4 py-2 text-sm font-bold text-white hover:bg-slate-900">Modificar</a>
                </div>
            </div>

            <dl class="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div><dt class="text-xs font-semibold text-slate-500">Chofer</dt><dd class="font-semibold text-slate-800">{{ $guia->nomchofer }}</dd></div>
                <div><dt class="text-xs font-semibold text-slate-500">Auxiliar</dt><dd class="font-semibold text-slate-800">{{ $guia->chof_aux_nom ?: '—' }}</dd></div>
                <div><dt class="text-xs font-semibold text-slate-500">Unidad</dt><dd class="font-semibold text-slate-800">{{ $guia->unidad ?: '—' }}</dd></div>
                <div><dt class="text-xs font-semibold text-slate-500">Salida</dt><dd class="font-semibold text-slate-800">{{ $fecha($guia->fecha_salida) ?? '—' }}</dd></div>
            </dl>

            <div class="grid gap-3 sm:grid-cols-3">
                @foreach ([['Clientes', $clientes->count(), null], ['Bultos cargados', "{$cargados} de {$bultos}", $porcentaje($cargados)], ['Bultos entregados', "{$entregados} de {$bultos}", $porcentaje($entregados)]] as [$titulo, $valor, $avance])
                    <div class="rounded-xl bg-slate-50 p-3 ring-1 ring-slate-200">
                        <p class="text-xs font-semibold text-slate-500">{{ $titulo }}</p>
                        <p class="text-xl font-black tabular-nums text-slate-900">{{ $valor }}</p>
                        @if ($avance !== null)
                            <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-200" role="progressbar" aria-label="{{ $titulo }}" aria-valuenow="{{ $avance }}" aria-valuemin="0" aria-valuemax="100">
                                <div class="h-full rounded-full bg-primary" style="width: {{ $avance }}%"></div>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>

        @if ($errors->any())
            <div role="alert" class="rounded-xl bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800 ring-1 ring-rose-200">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        @if ($clientes->isEmpty())
            <section class="rounded-2xl bg-white p-12 text-center shadow-sm ring-1 ring-slate-200">
                <h3 class="text-lg font-bold text-slate-900">La guía no tiene clientes</h3>
                <p class="mt-1 text-sm text-slate-500">Agrega pedidos pendientes abajo o elimina la guía.</p>
            </section>
        @endif

        @foreach ($clientes as $posicion => $cliente)
            @php
                $pendientesCliente = $cliente->pedidos->sum(fn ($p) => $p->bultos->whereNull('fecentregado')->count());
                $conEntregas = $cliente->pedidos->contains(fn ($p) => $p->bultos->whereNotNull('fecentregado')->isNotEmpty());
            @endphp
            <section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
                <header class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-slate-50 px-4 py-3">
                    <div class="flex items-center gap-3">
                        @if ($clientes->count() > 1)
                            <input type="checkbox" form="separar" name="clientes[]" value="{{ $cliente->codcli }}" aria-label="Separar a {{ $cliente->nomcli }}"
                                   class="size-5 rounded border-slate-300 text-primary focus:ring-primary">
                        @endif
                        <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-slate-800 text-sm font-black text-white" title="Orden de visita">{{ $posicion + 1 }}</span>
                        <div>
                            <h3 class="font-bold text-slate-900">{{ $cliente->nomcli }}</h3>
                            <p class="text-xs text-slate-500">{{ $cliente->codcli }} · {{ $cliente->totalBultos }} {{ $cliente->totalBultos === 1 ? 'bulto' : 'bultos' }}</p>
                        </div>
                        @if ($cliente->terminado)
                            <x-estado-guia estado="ENTREGADO" />
                        @elseif ($cliente->cargado)
                            <x-estado-guia estado="CARGADO" />
                        @endif
                    </div>
                    <div class="flex flex-wrap gap-2">
                        @if ($pendientesCliente > 0)
                            <form method="POST" action="{{ route('guias.entregar', $guia->id) }}"
                                  onsubmit="return confirm(@js('¿Marcar como entregados los '.$pendientesCliente.' bultos pendientes de '.$cliente->nomcli.'?'))">
                                @csrf
                                <input type="hidden" name="codcli" value="{{ $cliente->codcli }}">
                                <button type="submit" class="{{ $botonChico }} bg-emerald-600 text-white hover:bg-emerald-700">Entregar todo</button>
                            </form>
                        @endif
                        @unless ($conEntregas)
                            <form method="POST" action="{{ route('guias.clientes.destroy', $guia->id) }}"
                                  onsubmit="return confirm(@js('¿Quitar a '.$cliente->nomcli.' de la guía? Sus pedidos quedan pendientes de despacho.'))">
                                @csrf
                                @method('DELETE')
                                <input type="hidden" name="codcli" value="{{ $cliente->codcli }}">
                                <button type="submit" class="{{ $botonChico }} text-rose-700 ring-1 ring-rose-300 hover:bg-rose-50">Quitar cliente</button>
                            </form>
                        @endunless
                    </div>
                </header>

                <div class="divide-y divide-slate-100">
                    @foreach ($cliente->pedidos as $pedido)
                        <div class="grid gap-3 px-4 py-3 lg:grid-cols-[16rem_1fr]">
                            <div class="text-sm">
                                <p class="font-black text-slate-900">Pedido #{{ $pedido->id }}</p>
                                <p class="text-xs text-slate-500">
                                    Facturado {{ FechaSeped::mostrar($pedido->datos?->fecfacturado, 'd-m-y H:i') }}
                                    @if ($pedido->datos)
                                        · {{ $pedido->datos->numren }} renglones
                                    @endif
                                </p>
                                <p class="text-xs text-slate-500">
                                    Factura:
                                    {{ $pedido->facturas->isEmpty() ? 'sin factura en SEPED' : $pedido->facturas->pluck('factnum')->implode(', ') }}
                                </p>
                                @if (filled($pedido->datos?->observacion))
                                    <p class="mt-1 text-xs text-slate-600">{{ $pedido->datos->observacion }}</p>
                                @endif
                                @unless ($pedido->bultos->whereNotNull('fecentregado')->isNotEmpty())
                                    <form method="POST" action="{{ route('guias.pedidos.destroy', [$guia->id, $pedido->id]) }}" class="mt-2"
                                          onsubmit="return confirm(@js('¿Quitar el pedido #'.$pedido->id.' de la guía?'))">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-xs font-bold text-rose-700 hover:underline">Quitar pedido</button>
                                    </form>
                                @endunless
                            </div>
                            <ul class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                                @foreach ($pedido->bultos as $bulto)
                                    <li class="flex items-center justify-between gap-2 rounded-xl px-3 py-2 ring-1 ring-slate-200">
                                        <div class="min-w-0">
                                            <p class="font-mono text-sm font-bold text-slate-900">{{ $bulto->etiqueta }}</p>
                                            <x-estado-guia :estado="$bulto->estado" class="mt-0.5" />
                                            @if ($bulto->fecentregado)
                                                <p class="text-[11px] text-slate-500">Entregado {{ $fecha($bulto->fecentregado) }}</p>
                                            @elseif ($bulto->feccargado)
                                                <p class="text-[11px] text-slate-500">Cargado {{ $fecha($bulto->feccargado) }}</p>
                                            @endif
                                        </div>
                                        <div class="flex shrink-0 flex-col gap-1">
                                            @if (! $bulto->fecentregado)
                                                <form method="POST" action="{{ route('guias.entregar', $guia->id) }}">
                                                    @csrf
                                                    <input type="hidden" name="etiqueta" value="{{ $bulto->etiqueta }}">
                                                    <button type="submit" class="{{ $botonChico }} bg-emerald-50 text-emerald-800 ring-1 ring-emerald-200 hover:bg-emerald-100">Entregar</button>
                                                </form>
                                            @endif
                                            @if ($bulto->feccargado || $bulto->fecentregado)
                                                <form method="POST" action="{{ route('guias.reiniciar', $guia->id) }}"
                                                      onsubmit="return confirm(@js('¿Volver el bulto '.$bulto->etiqueta.' a pendiente de carga?'))">
                                                    @csrf
                                                    <input type="hidden" name="etiqueta" value="{{ $bulto->etiqueta }}">
                                                    <button type="submit" class="{{ $botonChico }} text-slate-600 ring-1 ring-slate-300 hover:bg-slate-50">Reiniciar</button>
                                                </form>
                                            @endif
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach

        @if ($clientes->count() > 1)
            <form id="separar" method="POST" action="{{ route('guias.separar', $guia->id) }}"
                  class="flex flex-wrap items-end gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                @csrf
                <div class="min-w-64 flex-1">
                    <h3 class="font-extrabold text-slate-900">Separar guía</h3>
                    <p class="text-sm text-slate-500">Marca clientes arriba (casilla junto al número) y pásalos a una guía nueva con otro chofer.</p>
                </div>
                <div>
                    <label for="chofer-separar" class="block text-xs font-semibold text-slate-600">Chofer de la guía nueva</label>
                    <select id="chofer-separar" name="chofer" required class="mt-1 block rounded-xl border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-primary focus:ring-primary">
                        <option value="">Elige el chofer</option>
                        @foreach ($choferes as $chofer)
                            <option value="{{ $chofer->chof_co }}">{{ $chofer->chof_nom }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="rounded-xl bg-slate-800 px-4 py-2 text-sm font-bold text-white hover:bg-slate-900">Separar</button>
            </form>
        @endif

        <section class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
            <div class="border-b border-slate-200 p-4">
                <h3 class="font-extrabold text-slate-900">Pedidos pendientes de la ruta</h3>
                <p class="text-sm text-slate-500">Pedidos facturados, con etiquetas, de clientes de {{ $guia->ruta }} que no están en ninguna guía.</p>
            </div>
            @if ($pendientes->isEmpty())
                <p class="p-4 text-sm text-slate-500">No hay pedidos pendientes para esta ruta.</p>
            @else
                <form method="POST" action="{{ route('guias.pedidos.store', $guia->id) }}" x-data="{ marcados: [] }">
                    @csrf
                    <ul class="divide-y divide-slate-100">
                        @foreach ($pendientes as $pendiente)
                            <li>
                                <label class="flex cursor-pointer items-center gap-3 px-4 py-2.5 hover:bg-slate-50 has-checked:bg-primary-soft">
                                    <input type="checkbox" name="pedidos[]" value="{{ $pendiente->id }}" x-model="marcados"
                                           class="size-5 rounded border-slate-300 text-primary focus:ring-primary">
                                    <span class="flex-1 text-sm">
                                        <span class="font-bold text-slate-900">#{{ $pendiente->id }}</span>
                                        <span class="text-slate-700">{{ $pendiente->nomcli }}</span>
                                        <span class="block text-xs text-slate-500">{{ $pendiente->codcli }} · facturado {{ FechaSeped::mostrar($pendiente->fecfacturado, 'd-m-y H:i') }}</span>
                                    </span>
                                    <span class="text-sm font-semibold tabular-nums text-slate-600">{{ $pendiente->bultos }} {{ $pendiente->bultos == 1 ? 'bulto' : 'bultos' }}</span>
                                </label>
                            </li>
                        @endforeach
                    </ul>
                    <div class="flex justify-end border-t border-slate-200 p-4">
                        <button type="submit" x-bind:disabled="marcados.length === 0"
                                class="rounded-xl bg-primary px-4 py-2 text-sm font-bold text-white hover:bg-primary-dark disabled:opacity-50">Agregar a la guía</button>
                    </div>
                </form>
            @endif
        </section>

        <form method="POST" action="{{ route('guias.destroy', $guia->id) }}"
              onsubmit="return confirm(@js('¿Eliminar la guía #'.$guia->id.'? Sus pedidos quedan pendientes de despacho.'))"
              class="flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-rose-200">
            @csrf
            @method('DELETE')
            <div>
                <h3 class="font-extrabold text-slate-900">Eliminar guía</h3>
                <p class="text-sm text-slate-500">Solo si no tiene bultos entregados. Sus pedidos vuelven a quedar pendientes de despacho.</p>
            </div>
            <button type="submit" class="rounded-xl px-4 py-2.5 text-sm font-bold text-rose-700 ring-1 ring-rose-300 hover:bg-rose-50">Eliminar</button>
        </form>
    </div>
</x-layouts.app>
