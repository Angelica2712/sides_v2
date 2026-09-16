@props(['estado'])

{{-- Estado de una guía o de un bulto (EN GUIA → CARGADO → ENTREGADO). --}}
@php
    $colores = match ($estado) {
        'ENTREGADO' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'CARGADO', 'TRANSITO' => 'bg-sky-50 text-sky-800 ring-sky-200',
        'CARGANDO' => 'bg-amber-50 text-amber-800 ring-amber-200',
        default => 'bg-slate-100 text-slate-700 ring-slate-200',
    };
    $texto = match ($estado) {
        'TRANSITO' => 'EN TRÁNSITO',
        'EN GUIA' => 'POR CARGAR',
        default => $estado,
    };
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-bold ring-1 {$colores}"]) }}>{{ $texto }}</span>
