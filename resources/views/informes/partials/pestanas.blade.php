{{--
    Pestañas para saltar entre los informes conservando las fechas.
    Recibe $fechas (desde/hasta en Y-m-d) y $actual: "picking/productividad"… o "fallas".
--}}
@php
    use App\Services\Informes\InformesService;

    $pestana = fn (bool $activa) => 'shrink-0 rounded-xl px-3.5 py-2 text-sm font-bold shadow-sm ring-1 transition-colors '
        .($activa ? 'bg-primary text-on-primary ring-primary' : 'bg-white text-slate-700 ring-slate-200 hover:bg-slate-50');
@endphp

<a href="{{ route('informes.index') }}" class="text-sm font-semibold text-primary-ink hover:underline">← Informes</a>

<nav aria-label="Informes" class="flex gap-2 overflow-x-auto pb-1">
    @foreach (InformesService::TIPOS as $t)
        @foreach (InformesService::VISTAS as $v)
            <a href="{{ route('informes.reporte', [$t, $v, ...$fechas]) }}"
               @if ($actual === "$t/$v") aria-current="page" @endif
               class="{{ $pestana($actual === "$t/$v") }}">
                {{ InformesService::textos($t, $v)['titulo'] }}
            </a>
        @endforeach
    @endforeach
    <a href="{{ route('informes.fallas', $fechas) }}" @if ($actual === 'fallas') aria-current="page" @endif class="{{ $pestana($actual === 'fallas') }}">Fallas</a>
</nav>
