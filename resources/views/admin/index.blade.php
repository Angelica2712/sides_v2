@php
    $nombresModulo = collect(\App\Support\MenuSides::OPCIONALES)
        ->mapWithKeys(fn ($clave) => [$clave => \App\Support\MenuSides::MODULOS[$clave][0]]);
@endphp

<x-layouts.app titulo="Administración">
    <div class="mx-auto max-w-6xl space-y-4">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-xl font-extrabold text-slate-900">Droguerías</h2>
                <p class="text-sm text-slate-500">Todas usan el mismo SIDES. Aquí decides qué módulos opcionales tiene cada una.</p>
            </div>
            <a href="{{ route('admin.create') }}" class="rounded-xl bg-primary px-4 py-2.5 text-sm font-bold text-white hover:bg-primary-dark">Nueva droguería</a>
        </div>

        <ul class="grid gap-3 md:grid-cols-2">
            @foreach ($droguerias as $drogueria)
                @php $activos = $modulosActivos[$drogueria->codisb] ?? []; @endphp
                <li class="flex flex-col gap-3 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate text-lg font-black text-slate-900">{{ $drogueria->nombre ?: $drogueria->codisb }}</p>
                            <p class="text-xs text-slate-500">
                                Código {{ $drogueria->codisb }} · {{ $usuarios[$drogueria->codisb] ?? 0 }} usuarios
                            </p>
                        </div>
                        <a href="{{ route('admin.edit', $drogueria->codisb) }}" class="shrink-0 rounded-xl bg-white px-3 py-2 text-sm font-semibold text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">Configurar</a>
                    </div>

                    <div class="flex flex-wrap gap-1.5" aria-label="Módulos opcionales">
                        @foreach ($nombresModulo as $clave => $nombre)
                            @php $activo = in_array($clave, $activos, true); @endphp
                            <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-bold ring-1 {{ $activo ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-slate-100 text-slate-400 ring-slate-200 line-through' }}">
                                {{ $nombre }}<span class="sr-only">{{ $activo ? ' (activo)' : ' (apagado)' }}</span>
                            </span>
                        @endforeach
                        <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-bold ring-1 {{ $drogueria->activarPacking ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-slate-100 text-slate-400 ring-slate-200 line-through' }}">
                            Packing<span class="sr-only">{{ $drogueria->activarPacking ? ' (activo)' : ' (apagado)' }}</span>
                        </span>
                    </div>

                    @if (in_array('batch', $activos, true) && $drogueria->procAlcabalaPicking)
                        <p class="text-xs text-slate-500">Los pedidos nuevos llegan en espera para agruparlos.</p>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>
</x-layouts.app>
