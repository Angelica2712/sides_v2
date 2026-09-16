@props(['estado'])

@php
    $colores = match ($estado) {
        'FACTURADO', 'PROCESADO', 'CERRADO' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'ANULADO' => 'bg-rose-50 text-rose-700 ring-rose-200',
        'PICKING', 'PACKING', 'PEND-FACTURA', 'FACTURANDO' => 'bg-amber-50 text-amber-800 ring-amber-200',
        default => 'bg-slate-100 text-slate-700 ring-slate-200',
    };
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-bold ring-1 {$colores}"]) }}>{{ $estado }}</span>
