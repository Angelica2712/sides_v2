<x-layouts.app titulo="Filtro monitor">
    <div class="mx-auto max-w-4xl space-y-4">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-xl font-extrabold text-slate-900">Filtro monitor</h2>
                <p class="text-sm text-slate-500">
                    {{ $filtros->count() }} {{ $filtros->count() === 1 ? 'filtro' : 'filtros' }} para la pantalla de Monitor
                </p>
            </div>
            <a href="{{ route('filtromonitor.create') }}" class="rounded-xl bg-primary px-4 py-2.5 text-sm font-bold text-white hover:bg-primary-dark">Nuevo filtro</a>
        </div>

        {{-- Guía corta: el módulo no se entiende sin ver qué hace en el Monitor. --}}
        <section aria-labelledby="como-funciona" class="rounded-2xl bg-primary-soft p-5 ring-1 ring-primary/20">
            <h3 id="como-funciona" class="flex items-center gap-2 font-extrabold text-slate-900">
                <svg class="size-5 text-primary" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! \App\Support\IconosSvg::path('filter') !!}</svg>
                ¿Para qué sirve?
            </h3>
            <p class="mt-2 text-sm text-slate-700">
                El Monitor muestra todos los pedidos en proceso de la sucursal. Un filtro agrupa varias rutas
                bajo un nombre y aparece como <strong>pestaña en el Monitor</strong>: al tocarla, solo se ven los
                pedidos de esas rutas. Sirve para que cada equipo (una zona, un piso del almacén, un turno)
                vea únicamente lo que le toca despachar.
            </p>
            <ol class="mt-3 grid gap-3 text-sm text-slate-700 sm:grid-cols-3">
                <li class="rounded-xl bg-white p-3 ring-1 ring-slate-200">
                    <span class="font-bold text-slate-900">1. Descripción</span><br>
                    El nombre de la pestaña. Ej.: <em>Centro</em>.
                </li>
                <li class="rounded-xl bg-white p-3 ring-1 ring-slate-200">
                    <span class="font-bold text-slate-900">2. Criterio</span><br>
                    Palabras de la ruta, separadas por coma. Un pedido entra si su ruta <strong>contiene</strong> alguna.
                    Ej.: <em>MIRANDA, TEQUES</em> toma «Miranda» y «LOS TEQUES».
                </li>
                <li class="rounded-xl bg-white p-3 ring-1 ring-slate-200">
                    <span class="font-bold text-slate-900">3. Marca (opcional)</span><br>
                    Etiqueta corta que se pinta junto a la ruta del pedido, aun en la pestaña «Todos». Ej.: <em>CTR</em>.
                </li>
            </ol>
            <p class="mt-3 text-xs text-slate-500">
                No importan mayúsculas ni minúsculas. Cuidado con palabras muy cortas: <em>CORO</em> también
                encuentra «SUR (LA COROMOTO)». Un pedido puede estar en más de un filtro.
            </p>
        </section>

        @if ($filtros->isEmpty())
            <section class="rounded-2xl bg-white p-12 text-center shadow-sm ring-1 ring-slate-200">
                <h3 class="text-lg font-bold text-slate-900">Todavía no hay filtros</h3>
                <p class="mt-1 text-sm text-slate-500">Crea uno para agrupar pedidos del Monitor por ruta.</p>
            </section>
        @else
            <x-tabla-desplazable etiqueta="Lista de filtros" class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-bold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th scope="col" class="px-4 py-3">Descripción</th>
                            <th scope="col" class="px-4 py-3">Criterio (rutas)</th>
                            <th scope="col" class="px-4 py-3">Marca</th>
                            <th scope="col" class="px-4 py-3"><span class="sr-only">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($filtros as $filtro)
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-3 font-semibold text-slate-800">{{ $filtro->descrip }}</td>
                                <td class="px-4 py-3 text-slate-600">{{ $filtro->criterio }}</td>
                                <td class="px-4 py-3 text-slate-600">{{ $filtro->caracterLogo ?: '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">
                                    @if ($verMonitor)
                                        <a href="{{ route('monitor.index', ['filtro' => $filtro->id]) }}" class="mr-3 font-semibold text-primary hover:underline">Ver en Monitor</a>
                                    @endif
                                    <a href="{{ route('filtromonitor.edit', $filtro->id) }}" class="font-semibold text-slate-500 hover:text-primary">Modificar</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-tabla-desplazable>
        @endif
    </div>
</x-layouts.app>
