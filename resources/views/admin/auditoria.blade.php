@php
    $campo = 'mt-1 block w-full rounded-xl border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-primary focus:ring-primary';
    $estilos = [
        'OK' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'FALLIDO' => 'bg-amber-50 text-amber-800 ring-amber-200',
        'DENEGADO' => 'bg-rose-50 text-rose-700 ring-rose-200',
    ];
@endphp

<x-layouts.app titulo="Auditoría">
    <div class="mx-auto max-w-7xl space-y-4">
        <a href="{{ route('admin.index') }}" class="text-sm font-semibold text-primary hover:underline">← Volver a droguerías</a>

        <div>
            <h2 class="text-xl font-extrabold text-slate-900">Auditoría</h2>
            <p class="text-sm text-slate-500">
                Lo que hace cada usuario en SIDES, en todas las droguerías: quién, qué, cuándo, desde dónde y con qué resultado.
                Las contraseñas y claves nunca se guardan.
            </p>
        </div>

        <form method="GET" action="{{ route('auditoria.index') }}" class="grid gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 sm:grid-cols-2 lg:grid-cols-4">
            <label class="text-xs font-semibold text-slate-600">
                Droguería
                <select name="codisb" class="{{ $campo }}">
                    <option value="">Todas</option>
                    @foreach ($droguerias as $codisb => $nombre)
                        <option value="{{ $codisb }}" @selected(($filtros['codisb'] ?? '') === (string) $codisb)>{{ $nombre ?: $codisb }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-xs font-semibold text-slate-600">
                Usuario
                <input type="text" name="usuario" value="{{ $filtros['usuario'] ?? '' }}" placeholder="Nombre o correo" class="{{ $campo }}">
            </label>
            <label class="text-xs font-semibold text-slate-600">
                Módulo
                <select name="modulo" class="{{ $campo }}">
                    <option value="">Todos</option>
                    @foreach ($modulos as $modulo)
                        <option value="{{ $modulo }}" @selected(($filtros['modulo'] ?? '') === $modulo)>{{ $modulo }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-xs font-semibold text-slate-600">
                Resultado
                <select name="resultado" class="{{ $campo }}">
                    <option value="">Todos</option>
                    @foreach (\App\Http\Controllers\AuditoriaController::RESULTADOS as $resultado)
                        <option value="{{ $resultado }}" @selected(($filtros['resultado'] ?? '') === $resultado)>{{ ucfirst(mb_strtolower($resultado)) }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-xs font-semibold text-slate-600">
                Desde
                <input type="date" name="desde" value="{{ $desde->format('Y-m-d') }}" class="{{ $campo }}">
            </label>
            <label class="text-xs font-semibold text-slate-600">
                Hasta
                <input type="date" name="hasta" value="{{ $hasta->format('Y-m-d') }}" class="{{ $campo }}">
            </label>
            <label class="text-xs font-semibold text-slate-600">
                Buscar
                <input type="text" name="buscar" value="{{ $filtros['buscar'] ?? '' }}" placeholder="Texto o número de pedido" class="{{ $campo }}">
            </label>
            <div class="flex items-end gap-2">
                <button type="submit" class="rounded-xl bg-primary px-4 py-2 text-sm font-bold text-white hover:bg-primary-dark">Filtrar</button>
                <a href="{{ route('auditoria.index') }}" class="rounded-xl px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100">Limpiar</a>
            </div>
        </form>

        @if ($errors->any())
            <div role="alert" class="rounded-xl bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800 ring-1 ring-rose-200">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        <p class="text-sm text-slate-500">
            Del {{ $desde->format('d-m-Y') }} al {{ $hasta->format('d-m-Y') }} ·
            {{ number_format($registros->total(), 0, ',', '.') }} {{ $registros->total() === 1 ? 'registro' : 'registros' }}
        </p>

        @if ($registros->isEmpty())
            <section class="rounded-2xl bg-white p-12 text-center shadow-sm ring-1 ring-slate-200">
                <h3 class="text-lg font-bold text-slate-900">No hay registros con estos filtros</h3>
            </section>
        @else
            <section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
                <x-tabla-desplazable etiqueta="Registros de auditoría">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-bold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th scope="col" class="px-4 py-3">Fecha</th>
                                <th scope="col" class="px-4 py-3">Usuario</th>
                                <th scope="col" class="px-4 py-3">Droguería</th>
                                <th scope="col" class="px-4 py-3">Módulo</th>
                                <th scope="col" class="px-4 py-3">Qué hizo</th>
                                <th scope="col" class="px-4 py-3">Resultado</th>
                                <th scope="col" class="px-4 py-3">IP</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 align-top">
                            @foreach ($registros as $registro)
                                <tr class="hover:bg-slate-50">
                                    <td class="whitespace-nowrap px-4 py-3 tabular-nums text-slate-600">{{ $registro->fecha->format('d-m-Y H:i:s') }}</td>
                                    <td class="px-4 py-3">
                                        <div class="font-semibold text-slate-800">{{ $registro->nombre ?: '—' }}</div>
                                        <div class="text-xs text-slate-500">{{ $registro->usuario }}</div>
                                    </td>
                                    <td class="px-4 py-3 text-slate-600">{{ $droguerias[$registro->codisb] ?? $registro->codisb ?? '—' }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ $registro->modulo }}</td>
                                    <td class="min-w-72 px-4 py-3">
                                        <div class="text-slate-800">{{ $registro->descripcion }}</div>
                                        @if ($registro->datos)
                                            <details class="mt-1">
                                                <summary class="cursor-pointer text-xs font-semibold text-primary">Ver datos enviados</summary>
                                                <pre class="mt-1 max-w-xl overflow-x-auto rounded-lg bg-slate-50 p-2 text-[11px] text-slate-700">{{ json_encode($registro->datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                                            </details>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-bold ring-1 {{ $estilos[$registro->resultado] ?? 'bg-slate-100 text-slate-700 ring-slate-200' }}">
                                            {{ ucfirst(mb_strtolower($registro->resultado)) }}
                                        </span>
                                        @if ($registro->detalle_resultado)
                                            <div class="mt-1 max-w-56 text-xs text-slate-500">{{ $registro->detalle_resultado }}</div>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 font-mono text-xs text-slate-500">{{ $registro->ip }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-tabla-desplazable>
            </section>

            @if ($registros->hasPages())
                <div class="rounded-2xl bg-white px-4 py-3 shadow-sm ring-1 ring-slate-200">{{ $registros->links() }}</div>
            @endif
        @endif
    </div>
</x-layouts.app>
