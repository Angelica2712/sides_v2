{{-- Marca de pedido partido por SEPED (ver App\Support\PartesPedido). $parte = ['raiz', 'parte', 'total', 'raizExiste'] o null. --}}
@props(['parte' => null])

@if ($parte)
    @php
        // El número lleva al pedido original (que muestra todas sus partes), si el usuario ve Pedidos.
        $usuario = auth()->user();
        $enlace = ($parte['raizExiste'] ?? true) && $usuario && \App\Support\MenuSides::puede($usuario, $usuario->cfg, 'pedidos')
            ? route('pedidos.show', $parte['raiz'])
            : null;
        $titulo = "SEPED partió el pedido #{$parte['raiz']} en {$parte['total']} (por el tope de renglones o por un criterio de separación)".($enlace ? '. Clic para ver el pedido original.' : '');
    @endphp
    <{{ $enlace ? 'a' : 'span' }} @if ($enlace) href="{{ $enlace }}" onclick="event.stopPropagation()" @endif
        {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 whitespace-nowrap rounded bg-sky-100 px-1.5 py-0.5 text-[11px] font-black normal-case tracking-normal text-sky-800 tabular-nums'.($enlace ? ' hover:bg-sky-200 hover:underline' : '')]) }}
        title="{{ $titulo }}">
        <svg class="size-3" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 3h5v5M8 3H3v5M21 3l-7 7M3 3l7 7M12 22v-8"/></svg>
        {{ $parte['parte'] }}/{{ $parte['total'] }}{{ $parte['parte'] > 1 ? ' · del #'.$parte['raiz'] : ' · original' }}
    </{{ $enlace ? 'a' : 'span' }}>
@endif
