@php
    use App\Services\Informes\InformesService;
@endphp

<x-layouts.app titulo="Informes">
    <div class="mx-auto max-w-5xl space-y-4">
        <div>
            <h2 class="text-xl font-extrabold text-slate-900">Informes</h2>
            <p class="text-sm text-slate-500">
                Rendimiento de cada operario, con los tiempos que SIDES registra al trabajar cada pedido,
                y las fallas de despacho. Por defecto muestran los últimos {{ InformesService::DIAS_POR_DEFECTO }} días.
            </p>
        </div>

        @foreach (InformesService::TIPOS as $tipo)
            <section aria-labelledby="informes-{{ $tipo }}" class="space-y-2">
                <h3 id="informes-{{ $tipo }}" class="text-xs font-bold uppercase tracking-wider text-slate-500">{{ ucfirst($tipo) }}</h3>
                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach (InformesService::VISTAS as $vista)
                        @php $textos = InformesService::textos($tipo, $vista); @endphp
                        <a href="{{ route('informes.reporte', [$tipo, $vista]) }}"
                           class="group flex gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:border-primary hover:shadow-md">
                            <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-primary-soft text-primary-ink transition-colors group-hover:bg-primary group-hover:text-on-primary">
                                <svg class="size-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    {!! \App\Support\IconosSvg::path($textos['icono']) !!}
                                </svg>
                            </span>
                            <span class="min-w-0">
                                <span class="block font-extrabold text-slate-900">{{ $textos['titulo'] }}</span>
                                <span class="mt-1 block text-sm text-slate-500">{{ $textos['detalle'] }}</span>
                            </span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endforeach

        <section aria-labelledby="informes-despacho" class="space-y-2">
            <h3 id="informes-despacho" class="text-xs font-bold uppercase tracking-wider text-slate-500">Despacho</h3>
            <div class="grid gap-3 sm:grid-cols-2">
                <a href="{{ route('informes.fallas') }}"
                   class="group flex gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:border-primary hover:shadow-md">
                    <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-primary-soft text-primary-ink transition-colors group-hover:bg-primary group-hover:text-on-primary">
                        <svg class="size-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            {!! \App\Support\IconosSvg::path('alert') !!}
                        </svg>
                    </span>
                    <span class="min-w-0">
                        <span class="block font-extrabold text-slate-900">Fallas</span>
                        <span class="mt-1 block text-sm text-slate-500">Lo que las farmacias pidieron y se despachó de menos: qué productos faltaron, cuántas unidades y en qué pedidos.</span>
                    </span>
                </a>
            </div>
        </section>
    </div>
</x-layouts.app>
