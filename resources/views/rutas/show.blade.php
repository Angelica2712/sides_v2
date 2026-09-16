@php
    $campo = 'block w-full rounded-xl border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-primary focus:ring-primary';
    $total = number_format($totalClientes, 0, ',', '.');
@endphp

<x-layouts.app :titulo="'Ruta '.$ruta->nombre">
    <div class="mx-auto max-w-7xl space-y-4">
        <a href="{{ route('rutas.index') }}" class="text-sm font-semibold text-primary hover:underline">← Volver a rutas</a>

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-xl font-extrabold text-slate-900">{{ $ruta->nombre }}</h2>
                <p class="text-sm text-slate-500">{{ $total }} {{ $totalClientes === 1 ? 'cliente' : 'clientes' }}, en el orden en que se visitan</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('rutas.descargar', $ruta->id) }}" class="rounded-xl bg-white px-4 py-2 text-sm font-bold text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">Descargar Excel</a>
                <a href="{{ route('rutas.agregar', $ruta->id) }}" class="rounded-xl bg-primary px-4 py-2 text-sm font-bold text-white hover:bg-primary-dark">Agregar clientes</a>
            </div>
        </div>

        @if ($errors->any())
            <div role="alert" class="rounded-xl bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800 ring-1 ring-rose-200">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        <section class="grid gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 md:grid-cols-2">
            <form method="POST" action="{{ route('rutas.update', $ruta->id) }}" class="flex items-end gap-2">
                @csrf
                @method('PUT')
                <div class="flex-1">
                    <label for="nombre" class="block text-xs font-semibold text-slate-600">Nombre de la ruta</label>
                    <input id="nombre" name="nombre" type="text" maxlength="100" required value="{{ old('nombre', $ruta->nombre) }}" class="mt-1 {{ $campo }}">
                </div>
                <button type="submit" class="rounded-xl bg-slate-800 px-4 py-2 text-sm font-bold text-white hover:bg-slate-900">Renombrar</button>
            </form>
            <form method="POST" action="{{ route('rutas.zona', $ruta->id) }}" class="flex items-end gap-2"
                  onsubmit="return confirm(@js('¿Poner esta zona a los '.$total.' clientes de la ruta?'))">
                @csrf
                @method('PUT')
                <div class="flex-1">
                    <label for="zona-general" class="block text-xs font-semibold text-slate-600">Misma zona para todos los clientes</label>
                    <input id="zona-general" name="zona" type="text" maxlength="100" required class="mt-1 {{ $campo }}">
                </div>
                <button type="submit" class="rounded-xl bg-slate-800 px-4 py-2 text-sm font-bold text-white hover:bg-slate-900">Aplicar</button>
            </form>
        </section>

        <form method="GET" action="{{ route('rutas.show', $ruta->id) }}" role="search"
              class="flex flex-wrap items-end gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
            <div class="min-w-56 flex-1">
                <label for="buscar" class="block text-xs font-semibold text-slate-600">Buscar en la ruta</label>
                <input id="buscar" name="buscar" type="search" value="{{ $buscar }}" placeholder="Código, cliente, RIF o zona" class="mt-1 {{ $campo }}">
            </div>
            <button type="submit" class="rounded-xl bg-slate-800 px-4 py-2 text-sm font-bold text-white hover:bg-slate-900">Buscar</button>
            @if ($buscar !== '')
                <a href="{{ route('rutas.show', $ruta->id) }}" class="rounded-xl px-3 py-2 text-sm font-semibold text-slate-500 hover:text-slate-800">Limpiar</a>
            @endif
        </form>

        @if ($clientes->isEmpty())
            <section class="rounded-2xl bg-white p-12 text-center shadow-sm ring-1 ring-slate-200">
                <h3 class="text-lg font-bold text-slate-900">{{ $buscar !== '' ? 'Ningún cliente coincide con la búsqueda' : 'La ruta no tiene clientes' }}</h3>
                @if ($buscar === '')
                    <a href="{{ route('rutas.agregar', $ruta->id) }}" class="mt-3 inline-block font-semibold text-primary hover:underline">Agregar clientes</a>
                @endif
            </section>
        @else
            <p class="text-xs text-slate-500">
                La secuencia marca el orden de visita (de menor a mayor). Van de 20 en 20 para poder intercalar: un cliente con 30 queda entre el 20 y el 40.
                Los que retiran en local no entran en las guías de la ruta.
            </p>
            <x-tabla-desplazable etiqueta="Clientes de la ruta" class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
                <table class="min-w-full text-sm">
                    <thead class="whitespace-nowrap bg-slate-50 text-left text-xs font-bold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th scope="col" class="px-4 py-3">Cliente</th>
                            <th scope="col" class="px-4 py-3">RIF</th>
                            <th scope="col" class="px-4 py-3">Zona</th>
                            <th scope="col" class="px-4 py-3">Secuencia</th>
                            <th scope="col" class="px-4 py-3">Retira en local</th>
                            <th scope="col" class="px-4 py-3"><span class="sr-only">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($clientes as $cliente)
                            @php $form = "cliente-{$cliente->item}"; @endphp
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-3">
                                    <p class="max-w-72 truncate font-semibold text-slate-800" title="{{ $cliente->nomcli }}">{{ $cliente->nomcli }}</p>
                                    <p class="text-xs text-slate-500">{{ $cliente->codcli }}</p>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ $cliente->rif ?: '—' }}</td>
                                <td class="px-4 py-3">
                                    <label for="zona-{{ $cliente->item }}" class="sr-only">Zona de {{ $cliente->nomcli }}</label>
                                    <input id="zona-{{ $cliente->item }}" form="{{ $form }}" name="zona" type="text" maxlength="100" value="{{ $cliente->zona }}" class="{{ $campo }} min-w-40">
                                </td>
                                <td class="px-4 py-3">
                                    <label for="sec-{{ $cliente->item }}" class="sr-only">Secuencia de {{ $cliente->nomcli }}</label>
                                    <input id="sec-{{ $cliente->item }}" form="{{ $form }}" name="sec" type="number" min="1" step="1" required value="{{ (int) $cliente->sec }}" class="{{ $campo }} w-28 tabular-nums">
                                </td>
                                <td class="px-4 py-3">
                                    <label class="inline-flex cursor-pointer items-center gap-2">
                                        <input form="{{ $form }}" name="retiraLocal" type="checkbox" value="1" @checked($cliente->retiraLocal)
                                               class="size-5 rounded border-slate-300 text-primary focus:ring-primary">
                                        <span class="text-slate-700">Sí</span>
                                    </label>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">
                                    <form id="{{ $form }}" method="POST" action="{{ route('rutas.clientes.update', [$ruta->id, $cliente->item]) }}" class="inline">
                                        @csrf
                                        @method('PUT')
                                        <button type="submit" class="rounded-lg bg-slate-800 px-3 py-1.5 text-xs font-bold text-white hover:bg-slate-900">Guardar</button>
                                    </form>
                                    <form method="POST" action="{{ route('rutas.clientes.destroy', [$ruta->id, $cliente->item]) }}" class="ml-1 inline"
                                          onsubmit="return confirm(@js('¿Quitar a '.$cliente->nomcli.' de la ruta?'))">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="rounded-lg px-3 py-1.5 text-xs font-bold text-rose-700 hover:bg-rose-50">Quitar</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-tabla-desplazable>

            @if ($clientes->hasPages())
                <nav aria-label="Paginación" class="flex flex-wrap items-center justify-between gap-3 text-sm">
                    <p class="text-slate-500">Página {{ $clientes->currentPage() }} de {{ $clientes->lastPage() }}</p>
                    <div class="flex gap-2">
                        @foreach ([['Anterior', $clientes->previousPageUrl()], ['Siguiente', $clientes->nextPageUrl()]] as [$texto, $url])
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

        <form method="POST" action="{{ route('rutas.destroy', $ruta->id) }}"
              onsubmit="return confirm(@js('¿Eliminar la ruta '.$ruta->nombre.' y sus '.$total.' clientes? No se puede deshacer.'))"
              class="flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-rose-200">
            @csrf
            @method('DELETE')
            <div>
                <h3 class="font-extrabold text-slate-900">Eliminar ruta</h3>
                <p class="text-sm text-slate-500">Los clientes quedan libres para otra ruta. Las guías ya hechas conservan el nombre.</p>
            </div>
            <button type="submit" class="rounded-xl px-4 py-2.5 text-sm font-bold text-rose-700 ring-1 ring-rose-300 hover:bg-rose-50">Eliminar</button>
        </form>
    </div>
</x-layouts.app>
