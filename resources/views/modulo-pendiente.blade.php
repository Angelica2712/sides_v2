<x-layouts.app :titulo="$modulo['etiqueta']">
    <div class="max-w-3xl mx-auto">
        <section class="rounded-2xl bg-white border border-slate-200 shadow-sm p-8 text-center">
            <span class="mx-auto flex items-center justify-center size-14 rounded-2xl bg-primary-soft text-primary">
                <svg class="size-7" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    {!! \App\Support\IconosSvg::path($modulo['icono']) !!}
                </svg>
            </span>
            <h2 class="mt-4 text-xl font-extrabold text-slate-900">{{ $modulo['etiqueta'] }}</h2>
            <p class="mt-1 text-slate-500">{{ $modulo['descripcion'] }}</p>
            <p class="mt-4 inline-flex rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-xs font-bold text-amber-700">
                En migración desde el SIDES anterior
            </p>
            <div class="mt-6">
                <a href="{{ route('home') }}"
                   class="inline-flex items-center rounded-xl bg-primary px-5 py-2.5 text-sm font-bold text-white transition-colors hover:bg-primary-dark">
                    Volver al inicio
                </a>
            </div>
        </section>
    </div>
</x-layouts.app>
