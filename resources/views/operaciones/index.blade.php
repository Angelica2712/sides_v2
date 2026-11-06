@php
    $reportes = [
        ['clock', 'Tiempos por día y hora', 'Cuánto se tarda en aceptar, hacer el picking y el packing cada día y en cada hora: muestra las horas pico y dónde se atasca el almacén.', route('operaciones.tiempos')],
        ['store', 'Clientes y rutas', 'Pedidos, renglones y unidades de cada cliente o ruta, qué tan completo se le despachó y cuánto tardó su pedido.', route('operaciones.clientes')],
        ['alert', 'Pedidos demorados', 'Los pedidos que pasaron más de cierto tiempo en aceptar, picking o packing, también los que siguen ahí ahora, y quién los tenía.', route('operaciones.demorados')],
        ['barchart', 'Resumen del mes', 'Una página con los números del mes contra el mes anterior, lista para imprimir o guardar en PDF.', route('operaciones.mensual')],
    ];
@endphp

<x-layouts.app titulo="Reportes de operaciones">
    <div class="mx-auto max-w-5xl space-y-4">
        <div>
            <h2 class="text-xl font-extrabold text-slate-900">Reportes de operaciones</h2>
            <p class="text-sm text-slate-500">
                Para el jefe de operaciones o del almacén: cómo trabaja el almacén, con los tiempos que SIDES registra en cada pedido.
                Todos se pueden descargar en Excel.
            </p>
        </div>

        <div class="grid gap-3 sm:grid-cols-2">
            @foreach ($reportes as [$icono, $titulo, $detalle, $url])
                <a href="{{ $url }}"
                   class="group flex gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:border-primary hover:shadow-md">
                    <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-primary-soft text-primary-ink transition-colors group-hover:bg-primary group-hover:text-on-primary">
                        <svg class="size-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            {!! \App\Support\IconosSvg::path($icono) !!}
                        </svg>
                    </span>
                    <span class="min-w-0">
                        <span class="block font-extrabold text-slate-900">{{ $titulo }}</span>
                        <span class="mt-1 block text-sm text-slate-500">{{ $detalle }}</span>
                    </span>
                </a>
            @endforeach
        </div>
    </div>
</x-layouts.app>
