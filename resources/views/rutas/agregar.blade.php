@php
    $campo = 'mt-1 block w-full rounded-xl border-slate-300 px-3.5 py-2 text-sm shadow-sm focus:border-primary focus:ring-primary';
@endphp

<x-layouts.app :titulo="'Agregar clientes a '.$ruta->nombre">
    <div class="mx-auto max-w-5xl space-y-4">
        <a href="{{ route('rutas.show', $ruta->id) }}" class="text-sm font-semibold text-primary hover:underline">← Volver a {{ $ruta->nombre }}</a>

        <div>
            <h2 class="text-xl font-extrabold text-slate-900">Agregar clientes a {{ $ruta->nombre }}</h2>
            <p class="text-sm text-slate-500">Clientes de SEPED que todavía no están en ninguna ruta. Se agregan al final, en el orden de la lista.</p>
        </div>

        @if ($errors->any())
            <div role="alert" class="rounded-xl bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800 ring-1 ring-rose-200">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        <form method="GET" action="{{ route('rutas.agregar', $ruta->id) }}" role="search"
              class="flex flex-wrap items-end gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
            <div class="min-w-56 flex-1">
                <label for="buscar" class="block text-xs font-semibold text-slate-600">Buscar cliente</label>
                <input id="buscar" name="buscar" type="search" value="{{ $buscar }}" placeholder="Código, nombre, RIF o ruta de SEPED" class="{{ $campo }}" autofocus>
            </div>
            <button type="submit" class="rounded-xl bg-slate-800 px-4 py-2 text-sm font-bold text-white hover:bg-slate-900">Buscar</button>
            @if ($buscar !== '')
                <a href="{{ route('rutas.agregar', $ruta->id) }}" class="rounded-xl px-3 py-2 text-sm font-semibold text-slate-500 hover:text-slate-800">Limpiar</a>
            @endif
        </form>

        @if ($clientes->isEmpty())
            <section class="rounded-2xl bg-white p-12 text-center shadow-sm ring-1 ring-slate-200">
                <h3 class="text-lg font-bold text-slate-900">{{ $buscar !== '' ? 'Ningún cliente libre coincide con la búsqueda' : 'No hay clientes sin ruta' }}</h3>
                <p class="mt-1 text-sm text-slate-500">Un cliente solo puede estar en una ruta: para cambiarlo, primero quítalo de la otra.</p>
            </section>
        @else
            <form method="POST" action="{{ route('rutas.clientes.store', $ruta->id) }}" class="space-y-3"
                  x-data="{ marcados: [], todos: @js($clientes->pluck('codcli')->values()) }">
                @csrf
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-sm text-slate-600">
                        @if ($clientes->count() >= 50)
                            Se muestran los primeros 50; busca para encontrar otros.
                        @else
                            {{ $clientes->count() }} {{ $clientes->count() === 1 ? 'cliente libre' : 'clientes libres' }}.
                        @endif
                        <span class="font-semibold" x-text="marcados.length + ' marcados'"></span>
                    </p>
                    <div class="flex gap-2">
                        <button type="button" x-on:click="marcados = marcados.length === todos.length ? [] : [...todos]"
                                class="rounded-xl bg-white px-4 py-2 text-sm font-bold text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50"
                                x-text="marcados.length === todos.length ? 'Desmarcar todos' : 'Marcar todos'">Marcar todos</button>
                        <button type="submit" x-bind:disabled="marcados.length === 0"
                                class="rounded-xl bg-primary px-4 py-2 text-sm font-bold text-white hover:bg-primary-dark disabled:opacity-50">Agregar a la ruta</button>
                    </div>
                </div>

                <x-tabla-desplazable etiqueta="Clientes sin ruta" class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-bold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th scope="col" class="w-12 px-4 py-3"><span class="sr-only">Marcar</span></th>
                                <th scope="col" class="px-4 py-3">Cliente</th>
                                <th scope="col" class="px-4 py-3">RIF</th>
                                <th scope="col" class="px-4 py-3">Ruta en SEPED</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($clientes as $cliente)
                                <tr class="hover:bg-slate-50 has-checked:bg-primary-soft">
                                    <td class="px-4 py-3">
                                        <input id="cli-{{ $loop->index }}" type="checkbox" name="clientes[]" value="{{ $cliente->codcli }}" x-model="marcados"
                                               class="size-5 rounded border-slate-300 text-primary focus:ring-primary">
                                    </td>
                                    <td class="px-4 py-3">
                                        <label for="cli-{{ $loop->index }}" class="block cursor-pointer">
                                            <span class="block font-semibold text-slate-800">{{ $cliente->nombre }}</span>
                                            <span class="block text-xs text-slate-500">{{ $cliente->codcli }}</span>
                                        </label>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ $cliente->rif ?: '—' }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ $cliente->ruta ?: '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-tabla-desplazable>
            </form>
        @endif
    </div>
</x-layouts.app>
