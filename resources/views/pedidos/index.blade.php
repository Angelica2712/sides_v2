@php
    use App\Support\FechaSeped;

    $numero = fn ($valor) => number_format((int) $valor, 0, ',', '.');
    $conFiltros = $filtros['texto'] !== '' || $filtros['estado'] !== '' || $filtros['desde'] !== '' || $filtros['hasta'] !== '';
    $filtrosSinEstado = array_filter(['buscar' => $filtros['texto'], 'desde' => $filtros['desde'], 'hasta' => $filtros['hasta']]);
    $chip = 'inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-bold ring-1';
@endphp

<x-layouts.app titulo="Pedidos">
    <div class="mx-auto max-w-7xl space-y-4">
        <div>
            <h2 class="text-xl font-extrabold text-slate-900">Pedidos</h2>
            <p class="text-sm text-slate-500">
                {{ $numero($pedidos->total()) }} {{ $pedidos->total() === 1 ? 'pedido' : 'pedidos' }}{{ $conFiltros ? ' con los filtros aplicados' : ' de la sucursal' }}, del más nuevo al más antiguo
            </p>
        </div>

        <form method="GET" action="{{ route('pedidos.index') }}" role="search"
              class="grid gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 md:grid-cols-[1fr_auto_auto_auto] md:items-end">
            @if ($filtros['estado'] !== '')
                <input type="hidden" name="estado" value="{{ $filtros['estado'] }}">
            @endif
            <div>
                <label for="buscar" class="block text-xs font-semibold text-slate-600">Buscar</label>
                <input id="buscar" name="buscar" type="search" value="{{ $filtros['texto'] }}" placeholder="Pedido, cliente, ruta, estado o recipiente"
                       class="mt-1 w-full rounded-xl border-slate-300 px-3.5 py-2 text-sm shadow-sm focus:border-primary focus:ring-primary">
            </div>
            <div>
                <label for="desde" class="block text-xs font-semibold text-slate-600">Enviado desde</label>
                <input id="desde" name="desde" type="date" value="{{ $filtros['desde'] }}"
                       class="mt-1 w-full rounded-xl border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-primary focus:ring-primary">
            </div>
            <div>
                <label for="hasta" class="block text-xs font-semibold text-slate-600">Hasta</label>
                <input id="hasta" name="hasta" type="date" value="{{ $filtros['hasta'] }}"
                       class="mt-1 w-full rounded-xl border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-primary focus:ring-primary">
            </div>
            <div class="flex gap-2">
                <button type="submit" class="rounded-xl bg-slate-800 px-4 py-2 text-sm font-bold text-white hover:bg-slate-900">Buscar</button>
                @if ($conFiltros)
                    <a href="{{ route('pedidos.index') }}" class="rounded-xl px-3 py-2 text-sm font-semibold text-slate-500 hover:text-slate-800">Limpiar</a>
                @endif
            </div>
        </form>

        <nav aria-label="Filtrar por estado" class="flex flex-wrap gap-2">
            <a href="{{ route('pedidos.index', $filtrosSinEstado) }}" @if ($filtros['estado'] === '') aria-current="true" @endif
               class="{{ $chip }} {{ $filtros['estado'] === '' ? 'bg-slate-800 text-white ring-slate-800' : 'bg-white text-slate-600 ring-slate-300 hover:bg-slate-50' }}">
                Todos <span class="tabular-nums opacity-75">{{ $numero($contadores->sum()) }}</span>
            </a>
            @foreach ($contadores as $estado => $cantidad)
                @php $activo = $filtros['estado'] === $estado; @endphp
                <a href="{{ route('pedidos.index', [...$filtrosSinEstado, 'estado' => $estado]) }}" @if ($activo) aria-current="true" @endif
                   class="{{ $chip }} {{ $activo ? 'bg-slate-800 text-white ring-slate-800' : 'bg-white text-slate-600 ring-slate-300 hover:bg-slate-50' }}">
                    {{ $estado }} <span class="tabular-nums opacity-75">{{ $numero($cantidad) }}</span>
                </a>
            @endforeach
        </nav>

        @if ($pedidos->isEmpty())
            <section class="rounded-2xl bg-white p-12 text-center shadow-sm ring-1 ring-slate-200">
                <h3 class="text-lg font-bold text-slate-900">{{ $conFiltros ? 'No hay pedidos que coincidan con los filtros' : 'Todavía no hay pedidos' }}</h3>
                <p class="mt-1 text-sm text-slate-500">Los pedidos llegan desde SEPED.</p>
            </section>
        @else
            <p class="text-xs text-slate-500 xl:hidden">Usa la barra de arriba de la tabla para ver el resto de las columnas.</p>
            <x-tabla-desplazable etiqueta="Lista de pedidos" class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-bold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th scope="col" class="sticky left-0 z-10 bg-slate-50 px-4 py-3">Pedido</th>
                            <th scope="col" class="px-4 py-3">Cliente</th>
                            <th scope="col" class="px-4 py-3">Ruta</th>
                            <th scope="col" class="px-4 py-3">Enviado</th>
                            <th scope="col" class="px-4 py-3">Procesado</th>
                            <th scope="col" class="px-4 py-3 text-right">Renglones</th>
                            <th scope="col" class="px-4 py-3 text-right">Unidades</th>
                            <th scope="col" class="px-4 py-3">Estado</th>
                            <th scope="col" class="px-4 py-3">Recipiente</th>
                            <th scope="col" class="px-4 py-3">Despachador</th>
                            <th scope="col" class="px-4 py-3"><span class="sr-only">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($pedidos as $pedido)
                            <tr class="group hover:bg-slate-50">
                                {{-- El número de pedido queda fijo a la izquierda al desplazar la tabla. --}}
                                <td class="sticky left-0 z-10 whitespace-nowrap bg-white px-4 py-3 shadow-[1px_0_0_var(--color-slate-100)] group-hover:bg-slate-50">
                                    <a href="{{ route('pedidos.show', $pedido->id) }}" class="text-base font-black text-primary hover:underline">#{{ $pedido->id }}</a>
                                </td>
                                <td class="px-4 py-3">
                                    <p class="max-w-64 truncate font-semibold text-slate-800">{{ $pedido->nomcli }}</p>
                                    <p class="text-xs text-slate-500">{{ $pedido->codcli }}</p>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 font-semibold text-slate-700">{{ $pedido->ruta ?: '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3 tabular-nums text-slate-600">{{ FechaSeped::mostrar($pedido->fecenviado, 'd-m-y H:i') }}</td>
                                <td class="whitespace-nowrap px-4 py-3 tabular-nums text-slate-600">{{ FechaSeped::mostrar($pedido->fecprocesado, 'd-m-y H:i') }}</td>
                                <td class="px-4 py-3 text-right tabular-nums font-semibold">{{ $numero($pedido->numren) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums font-semibold">{{ $numero($pedido->numund) }}</td>
                                <td class="px-4 py-3">
                                    <x-estado-pedido :estado="$pedido->estado" />
                                    @if (filled($pedido->documento))
                                        <p class="mt-1 max-w-40 truncate text-xs text-slate-500" title="Documento en el ERP">Doc. {{ $pedido->documento }}</p>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-700">{{ $pedido->recipiente ?: '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-700">{{ $pedido->despachador ?: '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">
                                    <a href="{{ route('pedidos.edit', $pedido->id) }}" class="font-semibold text-slate-500 hover:text-primary">Modificar</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-tabla-desplazable>

            @if ($pedidos->hasPages())
                <nav aria-label="Paginación" class="flex flex-wrap items-center justify-between gap-3 text-sm">
                    <p class="text-slate-500">Página {{ $pedidos->currentPage() }} de {{ $pedidos->lastPage() }}</p>
                    <div class="flex gap-2">
                        @foreach ([['Anterior', $pedidos->previousPageUrl()], ['Siguiente', $pedidos->nextPageUrl()]] as [$texto, $url])
                            @if ($url)
                                <a href="{{ $url }}" class="rounded-xl bg-white px-4 py-2 font-semibold text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">{{ $texto }}</a>
                            @else
                                <span class="rounded-xl px-4 py-2 font-semibold text-slate-300 ring-1 ring-slate-200">{{ $texto }}</span>
                            @endif
                        @endforeach
                    </div>
                </nav>
            @endif
        @endif
    </div>
</x-layouts.app>
