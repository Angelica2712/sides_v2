@php
    $numero = fn ($valor) => number_format((int) $valor, 0, ',', '.');

    $tarjetas = [
        ['etiqueta' => 'Recibidos', 'valor' => $contadores['recibido'], 'ruta' => 'monitor.index', 'query' => [], 'icono' => 'monitor', 've' => $veMonitor],
        ['etiqueta' => 'Picking', 'valor' => $contadores['picking'], 'ruta' => 'picking.index', 'query' => [], 'icono' => 'picking', 've' => $vePicking],
        ['etiqueta' => 'Packing', 'valor' => $contadores['packing'], 'ruta' => 'packing.index', 'query' => [], 'icono' => 'packing', 've' => $vePacking],
        ['etiqueta' => 'Pendiente por facturar', 'valor' => $contadores['facturando'], 'ruta' => 'pedidos.index', 'query' => ['estado' => 'PEND-FACTURA'], 'icono' => 'invoice', 've' => $vePedidos],
        ['etiqueta' => 'Facturados', 'valor' => $contadores['facturado'], 'ruta' => 'pedidos.index', 'query' => ['estado' => 'FACTURADO'], 'icono' => 'cash', 've' => $vePedidos],
        ['etiqueta' => 'Pedidos (todos)', 'valor' => $contadores['total'], 'ruta' => 'pedidos.index', 'query' => [], 'icono' => 'cart', 've' => $vePedidos],
    ];
@endphp

<x-layouts.app titulo="Resumen">
    <div class="mx-auto max-w-6xl space-y-4">
        <div>
            <h2 class="text-xl font-extrabold text-slate-900">Resumen</h2>
            <p class="text-sm text-slate-500">
                Operación del día · {{ $cfg?->nombre ?? 'Sucursal sin configuración' }} · {{ now()->format('d-m-Y') }}
            </p>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @if ($cfg)
                <div class="rounded-xl bg-white border border-slate-200 p-4 shadow-sm">
                    <span class="flex items-center justify-center size-11 shrink-0 rounded-lg bg-primary-soft text-primary-ink">
                        <svg class="size-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            {!! \App\Support\IconosSvg::path('alert') !!}
                        </svg>
                    </span>
                    <span class="mt-2 block text-2xl font-black text-slate-900">{{ $numero($cfg->pedidoxAprobar) }}</span>
                    <span class="block text-sm font-semibold text-slate-600">Pedidos por aprobar</span>
                </div>
            @endif

            @foreach ($tarjetas as $tarjeta)
                @if ($tarjeta['ve'])
                    <a href="{{ route($tarjeta['ruta'], $tarjeta['query']) }}"
                       class="group flex flex-col rounded-xl bg-white border border-slate-200 p-4 shadow-sm transition hover:border-primary hover:shadow-md">
                        <span class="flex items-center justify-center size-11 shrink-0 rounded-lg bg-primary-soft text-primary-ink transition-colors group-hover:bg-primary group-hover:text-on-primary">
                            <svg class="size-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                {!! \App\Support\IconosSvg::path($tarjeta['icono']) !!}
                            </svg>
                        </span>
                        <span class="mt-2 text-2xl font-black text-slate-900">{{ $numero($tarjeta['valor']) }}</span>
                        <span class="text-sm font-semibold text-slate-600">{{ $tarjeta['etiqueta'] }}</span>
                    </a>
                @endif
            @endforeach
        </div>
    </div>
</x-layouts.app>
