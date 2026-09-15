<x-layouts.app titulo="Inicio">
    <div class="max-w-6xl mx-auto space-y-6">
        <section class="rounded-2xl bg-white border border-slate-200 shadow-sm p-5 sm:p-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <h2 class="text-2xl font-extrabold text-slate-900">Hola, {{ auth()->user()->name }}</h2>
                <p class="mt-1 text-sm text-slate-500">
                    {{ $cfg?->nombre ?? 'Sucursal sin configuración' }} · Sucursal {{ auth()->user()->codisb }}
                </p>
            </div>
            <span class="inline-flex items-center self-start sm:self-center rounded-full bg-primary-soft px-3 py-1 text-xs font-bold text-primary">
                {{ count($modulos) }} {{ count($modulos) === 1 ? 'módulo disponible' : 'módulos disponibles' }}
            </span>
        </section>

        @if (empty($modulos))
            <section class="rounded-2xl bg-white border border-slate-200 shadow-sm p-8 text-center">
                <h2 class="text-lg font-bold text-slate-900">No tienes módulos habilitados</h2>
                <p class="mt-1 text-sm text-slate-500">Pide a un administrador de SIDES que te asigne permisos.</p>
            </section>
        @else
            <section aria-labelledby="titulo-modulos">
                <h2 id="titulo-modulos" class="mb-3 text-sm font-bold uppercase tracking-wide text-slate-500">Tus módulos</h2>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($modulos as $modulo)
                        <a href="{{ route($modulo['ruta']) }}"
                           class="group flex items-start gap-3 rounded-xl bg-white border border-slate-200 p-4 shadow-sm transition hover:border-primary hover:shadow-md">
                            <span class="flex items-center justify-center size-11 shrink-0 rounded-lg bg-primary-soft text-primary transition-colors group-hover:bg-primary group-hover:text-white">
                                <svg class="size-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    {!! \App\Support\IconosSvg::path($modulo['icono']) !!}
                                </svg>
                            </span>
                            <span class="min-w-0">
                                <span class="block font-bold text-slate-900">{{ $modulo['etiqueta'] }}</span>
                                <span class="block text-sm text-slate-500">{{ $modulo['descripcion'] }}</span>
                            </span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</x-layouts.app>
