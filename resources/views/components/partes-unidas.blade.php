{{-- Partes de un pedido que SIDES trabaja como uno solo (sides_cfg.unirPedidosPartidos). $unidos = números de pedido. --}}
@props(['unidos' => []])

@if (count($unidos) > 1)
    <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 whitespace-nowrap rounded bg-sky-100 px-1.5 py-0.5 text-[11px] font-black normal-case tracking-normal text-sky-800 tabular-nums']) }}
          title="SEPED separó este pedido; aquí se trabaja como uno solo. Cada parte se factura aparte.">
        {{ count($unidos) }} partes unidas: {{ collect($unidos)->map(fn ($id) => '#'.$id)->implode(', ') }}
    </span>
@endif
