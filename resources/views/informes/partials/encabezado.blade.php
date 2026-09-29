{{--
    Encabezado compartido por informes/reporte y informes/operario: título, pestañas para saltar
    entre los cuatro informes conservando las fechas, y el filtro de fechas.
    Recibe $tipo, $vista, $desde, $hasta y, opcional, $accion (a dónde envía el filtro).
--}}
@php
    use App\Services\Informes\InformesService;

    $textos = InformesService::textos($tipo, $vista);
    $fechas = ['desde' => $desde->format('Y-m-d'), 'hasta' => $hasta->format('Y-m-d')];
    $campo = 'mt-1 block rounded-xl border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-primary focus:ring-primary';
@endphp

<a href="{{ route('informes.index') }}" class="text-sm font-semibold text-primary hover:underline">← Informes</a>

<nav aria-label="Informes" class="flex gap-2 overflow-x-auto pb-1">
    @foreach (InformesService::TIPOS as $t)
        @foreach (InformesService::VISTAS as $v)
            @php $activa = $t === $tipo && $v === $vista; @endphp
            <a href="{{ route('informes.reporte', [$t, $v, ...$fechas]) }}"
               @if ($activa) aria-current="page" @endif
               class="shrink-0 rounded-xl px-3.5 py-2 text-sm font-bold shadow-sm ring-1 transition-colors {{ $activa ? 'bg-primary text-white ring-primary' : 'bg-white text-slate-700 ring-slate-200 hover:bg-slate-50' }}">
                {{ InformesService::textos($t, $v)['titulo'] }}
            </a>
        @endforeach
    @endforeach
</nav>

<div class="flex flex-wrap items-end justify-between gap-3">
    <div class="min-w-0">
        <h2 class="text-xl font-extrabold text-slate-900">{{ $titulo ?? $textos['titulo'] }}</h2>
        <p class="text-sm text-slate-500">{{ $textos['detalle'] }}</p>
    </div>

    <form method="GET" action="{{ $accion ?? route('informes.reporte', [$tipo, $vista]) }}" class="flex flex-wrap items-end gap-2">
        <label class="text-xs font-semibold text-slate-600">
            Desde
            <input type="date" name="desde" value="{{ $fechas['desde'] }}" required class="{{ $campo }}">
        </label>
        <label class="text-xs font-semibold text-slate-600">
            Hasta
            <input type="date" name="hasta" value="{{ $fechas['hasta'] }}" required class="{{ $campo }}">
        </label>
        <button type="submit" class="rounded-xl bg-primary px-4 py-2 text-sm font-bold text-white hover:bg-primary-dark">Consultar</button>
    </form>
</div>

@if ($errors->any())
    <div role="alert" class="rounded-xl bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800 ring-1 ring-rose-200">
        @foreach ($errors->all() as $error)
            <p>{{ $error }}</p>
        @endforeach
    </div>
@endif
