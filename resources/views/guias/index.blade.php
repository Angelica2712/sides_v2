@php
    use App\Services\Guias\GuiasService;
    use Illuminate\Support\Carbon;

    $campo = 'mt-1 block w-full rounded-xl border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-primary focus:ring-primary';
    $conFiltros = implode('', $filtros) !== '';
@endphp

<x-layouts.app titulo="Guías">
    <div class="mx-auto max-w-7xl space-y-4">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-xl font-extrabold text-slate-900">Guías de despacho</h2>
                <p class="text-sm text-slate-500">Los bultos de los pedidos facturados que salen en cada viaje, por ruta y chofer.</p>
            </div>
            <a href="{{ route('guias.create') }}" class="rounded-xl bg-primary px-4 py-2.5 text-sm font-bold text-white hover:bg-primary-dark">Nueva guía</a>
        </div>

        <form method="GET" action="{{ route('guias.index') }}" role="search"
              class="grid gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 sm:grid-cols-2 lg:grid-cols-[auto_auto_1fr_1fr_auto] lg:items-end">
            <div>
                <label for="fecha" class="block text-xs font-semibold text-slate-600">Fecha</label>
                <input id="fecha" name="fecha" type="date" value="{{ $filtros['fecha'] }}" class="{{ $campo }}">
            </div>
            <div>
                <label for="estado" class="block text-xs font-semibold text-slate-600">Estado</label>
                <select id="estado" name="estado" class="{{ $campo }}">
                    <option value="">Todos</option>
                    @foreach (GuiasService::ESTADOS as $estado)
                        <option value="{{ $estado }}" @selected($filtros['estado'] === $estado)>{{ $estado }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="ruta" class="block text-xs font-semibold text-slate-600">Ruta</label>
                <select id="ruta" name="ruta" class="{{ $campo }}">
                    <option value="">Todas</option>
                    @foreach ($rutas as $ruta)
                        <option value="{{ $ruta }}" @selected($filtros['ruta'] === $ruta)>{{ $ruta }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="chofer" class="block text-xs font-semibold text-slate-600">Chofer</label>
                <select id="chofer" name="chofer" class="{{ $campo }}">
                    <option value="">Todos</option>
                    @foreach ($choferes as $codigo => $nombre)
                        <option value="{{ $codigo }}" @selected($filtros['chofer'] === (string) $codigo)>{{ $nombre }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="rounded-xl bg-slate-800 px-4 py-2 text-sm font-bold text-white hover:bg-slate-900">Filtrar</button>
                @if ($conFiltros)
                    <a href="{{ route('guias.index') }}" class="rounded-xl px-3 py-2 text-sm font-semibold text-slate-500 hover:text-slate-800">Limpiar</a>
                @endif
            </div>
        </form>

        @if ($guias->isEmpty())
            <section class="rounded-2xl bg-white p-12 text-center shadow-sm ring-1 ring-slate-200">
                <h3 class="text-lg font-bold text-slate-900">{{ $conFiltros ? 'Ninguna guía coincide con los filtros' : 'Todavía no hay guías' }}</h3>
                <p class="mt-1 text-sm text-slate-500">Una guía toma los pedidos facturados, con etiquetas impresas, de los clientes de una ruta.</p>
            </section>
        @else
            <x-tabla-desplazable etiqueta="Lista de guías" class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
                <table class="min-w-full text-sm">
                    <thead class="whitespace-nowrap bg-slate-50 text-left text-xs font-bold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th scope="col" class="px-4 py-3">Guía</th>
                            <th scope="col" class="px-4 py-3">Fecha</th>
                            <th scope="col" class="px-4 py-3">Ruta</th>
                            <th scope="col" class="px-4 py-3">Chofer</th>
                            <th scope="col" class="px-4 py-3 text-right">Clientes</th>
                            <th scope="col" class="px-4 py-3 text-right">Bultos</th>
                            <th scope="col" class="px-4 py-3">Estado</th>
                            <th scope="col" class="px-4 py-3"><span class="sr-only">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($guias as $guia)
                            <tr class="hover:bg-slate-50">
                                <td class="whitespace-nowrap px-4 py-3">
                                    <a href="{{ route('guias.show', $guia->id) }}" class="text-base font-black text-primary hover:underline">#{{ $guia->id }}</a>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 tabular-nums text-slate-600">{{ Carbon::parse($guia->fecha)->format('d-m-y H:i') }}</td>
                                <td class="whitespace-nowrap px-4 py-3 font-semibold text-slate-800">{{ $guia->ruta }}</td>
                                <td class="px-4 py-3">
                                    <p class="font-semibold text-slate-800">{{ $guia->nomchofer }}</p>
                                    @if ($guia->chof_aux_nom)
                                        <p class="text-xs text-slate-500">Auxiliar: {{ $guia->chof_aux_nom }}</p>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ $guia->clientes }}</td>
                                <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ $guia->bultos }}</td>
                                <td class="px-4 py-3"><x-estado-guia :estado="$guia->estado" /></td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">
                                    <a href="{{ route('guias.imprimir', $guia->id) }}" class="font-semibold text-slate-500 hover:text-primary">Imprimir</a>
                                    <a href="{{ route('guias.show', $guia->id) }}" class="ml-4 font-semibold text-slate-500 hover:text-primary">Ver</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-tabla-desplazable>

            @if ($guias->hasPages())
                <nav aria-label="Paginación" class="flex flex-wrap items-center justify-between gap-3 text-sm">
                    <p class="text-slate-500">Página {{ $guias->currentPage() }} de {{ $guias->lastPage() }}</p>
                    <div class="flex gap-2">
                        @foreach ([['Anterior', $guias->previousPageUrl()], ['Siguiente', $guias->nextPageUrl()]] as [$texto, $url])
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
