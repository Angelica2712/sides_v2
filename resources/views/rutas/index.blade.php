@php
    use App\Services\Rutas\RutasService;
    use Illuminate\Support\Carbon;

    $campo = 'mt-1 block w-full rounded-xl border-slate-300 px-3.5 py-2 text-sm shadow-sm focus:border-primary focus:ring-primary';
    $boton = 'rounded-xl bg-primary px-4 py-2 text-sm font-bold text-white hover:bg-primary-dark';
    // Tras un error, se reabre el panel desde el que se envió el formulario.
    $panel = old('panel', '');
@endphp

<x-layouts.app titulo="Rutas">
    <div class="mx-auto max-w-6xl space-y-4">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-xl font-extrabold text-slate-900">Rutas</h2>
                <p class="text-sm text-slate-500">Clientes que visita cada ruta de despacho y en qué orden. Las guías usan este orden.</p>
            </div>
            @if ($sincronizada)
                <form method="POST" action="{{ route('rutas.sincronizar') }}">
                    @csrf
                    <button type="submit" class="{{ $boton }}">Sincronizar ahora</button>
                </form>
            @endif
        </div>

        @if ($errors->any())
            <div role="alert" class="rounded-xl bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800 ring-1 ring-rose-200">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        @if ($sincronizada)
            <p class="rounded-xl bg-sky-50 px-4 py-3 text-sm text-sky-900 ring-1 ring-sky-200">
                Las rutas se sincronizan cada hora con las rutas de los clientes en SEPED. Puedes cambiar zonas, secuencias y retiro en local; para crear rutas a mano, apaga la sincronización en Configuración.
            </p>
        @else
            <section class="grid items-start gap-3 md:grid-cols-3">
                <details class="group rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 open:ring-primary" @if ($panel === 'nueva') open @endif>
                    <summary class="cursor-pointer list-none p-4">
                        <span class="block font-bold text-slate-900">Nueva ruta</span>
                        <span class="block text-sm text-slate-500">Ponle nombre y elige sus clientes uno a uno.</span>
                    </summary>
                    <form method="POST" action="{{ route('rutas.store') }}" class="space-y-3 border-t border-slate-200 p-4">
                        @csrf
                        <input type="hidden" name="panel" value="nueva">
                        <div>
                            <label for="nombre-nueva" class="block text-xs font-semibold text-slate-600">Nombre de la ruta</label>
                            <input id="nombre-nueva" name="nombre" type="text" maxlength="100" required placeholder="RUTA CENTRO"
                                   value="{{ $panel === 'nueva' ? old('nombre') : '' }}" class="{{ $campo }}">
                        </div>
                        <button type="submit" class="{{ $boton }}">Crear y agregar clientes</button>
                    </form>
                </details>

                <details class="group rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 open:ring-primary" @if ($panel === 'seped') open @endif>
                    <summary class="cursor-pointer list-none p-4">
                        <span class="block font-bold text-slate-900">Copiar una ruta de SEPED</span>
                        <span class="block text-sm text-slate-500">Toma los clientes que SEPED tiene en esa ruta.</span>
                    </summary>
                    <form method="POST" action="{{ route('rutas.seped') }}" class="space-y-3 border-t border-slate-200 p-4">
                        @csrf
                        <input type="hidden" name="panel" value="seped">
                        @if ($rutasSeped->isEmpty())
                            <p class="text-sm text-slate-500">Los clientes de la sucursal no tienen rutas en SEPED.</p>
                        @else
                            <div>
                                <label for="ruta-seped" class="block text-xs font-semibold text-slate-600">Ruta de SEPED</label>
                                <select id="ruta-seped" name="ruta_seped" required class="{{ $campo }}">
                                    <option value="">Elige una ruta</option>
                                    @foreach ($rutasSeped as $rutaSeped)
                                        <option value="{{ $rutaSeped->ruta }}" @selected(old('ruta_seped') === $rutaSeped->ruta)>
                                            {{ $rutaSeped->ruta }} ({{ $rutaSeped->clientes }} {{ $rutaSeped->clientes == 1 ? 'cliente' : 'clientes' }})
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="nombre-seped" class="block text-xs font-semibold text-slate-600">Nombre en SIDES <span class="font-normal text-slate-400">(opcional)</span></label>
                                <input id="nombre-seped" name="nombre" type="text" maxlength="100" placeholder="El mismo de SEPED"
                                       value="{{ $panel === 'seped' ? old('nombre') : '' }}" class="{{ $campo }}">
                            </div>
                            <button type="submit" class="{{ $boton }}">Crear ruta</button>
                        @endif
                    </form>
                </details>

                <details class="group rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 open:ring-primary" @if ($panel === 'excel') open @endif>
                    <summary class="cursor-pointer list-none p-4">
                        <span class="block font-bold text-slate-900">Subir un Excel</span>
                        <span class="block text-sm text-slate-500">Una ruta armada en una hoja de cálculo.</span>
                    </summary>
                    <form method="POST" action="{{ route('rutas.importar') }}" enctype="multipart/form-data" class="space-y-3 border-t border-slate-200 p-4">
                        @csrf
                        <input type="hidden" name="panel" value="excel">
                        <div>
                            <label for="nombre-excel" class="block text-xs font-semibold text-slate-600">Nombre de la ruta</label>
                            <input id="nombre-excel" name="nombre" type="text" maxlength="100" required
                                   value="{{ $panel === 'excel' ? old('nombre') : '' }}" class="{{ $campo }}">
                        </div>
                        <div>
                            <label for="archivo" class="block text-xs font-semibold text-slate-600">Archivo .xlsx</label>
                            <input id="archivo" name="archivo" type="file" required accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                                   class="mt-1 block w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:font-semibold file:text-slate-700">
                        </div>
                        <p class="text-xs text-slate-500">
                            Primera fila de encabezado y luego una fila por cliente con estas columnas:
                            <span class="font-semibold text-slate-700">{{ implode(', ', RutasService::COLUMNAS_EXCEL) }}</span>.
                            ORDEN es la posición del cliente en la ruta (1, 2, 3…). Es el mismo formato que se descarga de cada ruta.
                        </p>
                        <button type="submit" class="{{ $boton }}">Subir y crear ruta</button>
                    </form>
                </details>
            </section>
        @endif

        <form method="GET" action="{{ route('rutas.index') }}" role="search"
              class="flex flex-wrap items-end gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
            <div class="min-w-56 flex-1">
                <label for="buscar" class="block text-xs font-semibold text-slate-600">Buscar ruta</label>
                <input id="buscar" name="buscar" type="search" value="{{ $buscar }}" placeholder="Nombre de la ruta" class="{{ $campo }}">
            </div>
            <button type="submit" class="rounded-xl bg-slate-800 px-4 py-2 text-sm font-bold text-white hover:bg-slate-900">Buscar</button>
            @if ($buscar !== '')
                <a href="{{ route('rutas.index') }}" class="rounded-xl px-3 py-2 text-sm font-semibold text-slate-500 hover:text-slate-800">Limpiar</a>
            @endif
        </form>

        @if ($rutas->isEmpty())
            <section class="rounded-2xl bg-white p-12 text-center shadow-sm ring-1 ring-slate-200">
                <h3 class="text-lg font-bold text-slate-900">{{ $buscar !== '' ? 'Ninguna ruta coincide con la búsqueda' : 'Todavía no hay rutas' }}</h3>
                @if ($buscar === '')
                    <p class="mt-1 text-sm text-slate-500">{{ $sincronizada ? 'Se crean en la próxima sincronización con SEPED.' : 'Créalas con una de las opciones de arriba.' }}</p>
                @endif
            </section>
        @else
            <x-tabla-desplazable etiqueta="Lista de rutas" class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-bold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th scope="col" class="px-4 py-3">Ruta</th>
                            <th scope="col" class="px-4 py-3 text-right">Clientes</th>
                            <th scope="col" class="px-4 py-3">Creada</th>
                            <th scope="col" class="px-4 py-3"><span class="sr-only">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($rutas as $ruta)
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-3">
                                    <a href="{{ route('rutas.show', $ruta->id) }}" class="font-bold text-primary hover:underline">{{ $ruta->nombre }}</a>
                                </td>
                                <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ number_format($ruta->clientes_count, 0, ',', '.') }}</td>
                                <td class="whitespace-nowrap px-4 py-3 tabular-nums text-slate-600">{{ $ruta->fecha ? Carbon::parse($ruta->fecha)->format('d-m-y H:i') : '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">
                                    <a href="{{ route('rutas.descargar', $ruta->id) }}" class="font-semibold text-slate-500 hover:text-primary">Excel</a>
                                    <a href="{{ route('rutas.show', $ruta->id) }}" class="ml-4 font-semibold text-slate-500 hover:text-primary">Modificar</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-tabla-desplazable>

            @if ($rutas->hasPages())
                <nav aria-label="Paginación" class="flex flex-wrap items-center justify-between gap-3 text-sm">
                    <p class="text-slate-500">Página {{ $rutas->currentPage() }} de {{ $rutas->lastPage() }}</p>
                    <div class="flex gap-2">
                        @foreach ([['Anterior', $rutas->previousPageUrl()], ['Siguiente', $rutas->nextPageUrl()]] as [$texto, $url])
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
