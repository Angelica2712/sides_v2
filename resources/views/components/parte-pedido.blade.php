{{-- Marca de pedido partido por SEPED (ver App\Support\PartesPedido). $parte = ['raiz', 'parte', 'total'] o null. --}}
@props(['parte' => null])

@if ($parte)
    <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 whitespace-nowrap rounded bg-sky-100 px-1.5 py-0.5 text-[11px] font-black normal-case tracking-normal text-sky-800 tabular-nums']) }}
          title="SEPED partió el pedido #{{ $parte['raiz'] }} en {{ $parte['total'] }} por exceso de renglones">
        <svg class="size-3" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 3h5v5M8 3H3v5M21 3l-7 7M3 3l7 7M12 22v-8"/></svg>
        {{ $parte['parte'] }}/{{ $parte['total'] }}{{ $parte['parte'] > 1 ? ' · del #'.$parte['raiz'] : ' · original' }}
    </span>
@endif
