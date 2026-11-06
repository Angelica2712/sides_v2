{{--
    Encabezado de los reportes de operaciones: volver, pestañas entre los reportes (conservan las
    fechas), título y filtro. Recibe $actual (tiempos|clientes|demorados|mensual), $titulo, $detalle,
    $accion y, opcional, $desde/$hasta (filtro de fechas), $extra (más campos del filtro) y $excel.
--}}
@php
    $fechas = isset($desde) ? ['desde' => $desde->format('Y-m-d'), 'hasta' => $hasta->format('Y-m-d')] : [];
    $campo = 'mt-1 block rounded-xl border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-primary focus:ring-primary';
    $pestanas = [
        'tiempos' => ['Tiempos por día y hora', route('operaciones.tiempos', $fechas)],
        'clientes' => ['Clientes y rutas', route('operaciones.clientes', $fechas)],
        'demorados' => ['Pedidos demorados', route('operaciones.demorados', $fechas)],
        'mensual' => ['Resumen del mes', route('operaciones.mensual')],
    ];
@endphp

<div class="space-y-4 print:hidden">
    <a href="{{ route('operaciones.index') }}" class="text-sm font-semibold text-primary-ink hover:underline">← Reportes de operaciones</a>

    <nav aria-label="Reportes de operaciones" class="flex gap-2 overflow-x-auto pb-1">
        @foreach ($pestanas as $clave => [$nombre, $url])
            <a href="{{ $url }}" @if ($actual === $clave) aria-current="page" @endif
               class="shrink-0 rounded-xl px-3.5 py-2 text-sm font-bold shadow-sm ring-1 transition-colors {{ $actual === $clave ? 'bg-primary text-on-primary ring-primary' : 'bg-white text-slate-700 ring-slate-200 hover:bg-slate-50' }}">{{ $nombre }}</a>
        @endforeach
    </nav>

    <div class="flex flex-wrap items-end justify-between gap-3">
        <div class="min-w-0">
            <h2 class="text-xl font-extrabold text-slate-900">{{ $titulo }}</h2>
            <p class="text-sm text-slate-500">{{ $detalle }}</p>
        </div>

        <form method="GET" action="{{ $accion }}" class="flex flex-wrap items-end gap-2">
            {!! $extra ?? '' !!}
            @isset($desde)
                <label class="text-xs font-semibold text-slate-600">
                    Llegados desde
                    <input type="date" name="desde" value="{{ $fechas['desde'] }}" required class="{{ $campo }}">
                </label>
                <label class="text-xs font-semibold text-slate-600">
                    Hasta
                    <input type="date" name="hasta" value="{{ $fechas['hasta'] }}" required class="{{ $campo }}">
                </label>
            @endisset
            <button type="submit" class="rounded-xl bg-primary px-4 py-2 text-sm font-bold text-on-primary hover:bg-primary-dark">Consultar</button>
            @isset($excel)
                <a href="{{ $excel }}" class="flex items-center gap-1.5 rounded-xl bg-emerald-600 px-3 py-2 text-sm font-bold text-white hover:bg-emerald-700">
                    <svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! \App\Support\IconosSvg::path('download') !!}</svg>
                    Excel
                </a>
            @endisset
        </form>
    </div>

    @if ($errors->any())
        <div role="alert" class="rounded-xl bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800 ring-1 ring-rose-200">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif
</div>
