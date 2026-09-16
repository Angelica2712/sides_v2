@php
    use Illuminate\Support\Carbon;
@endphp

<x-layouts.app titulo="Carga y descarga">
    <div class="mx-auto max-w-5xl space-y-4">
        <div>
            <h2 class="text-xl font-extrabold text-slate-900">Carga y descarga</h2>
            <p class="text-sm text-slate-500">
                {{ $esChofer ? 'Tus guías en curso, como chofer o auxiliar.' : 'Guías en curso de la sucursal.' }}
                Primero se cargan todos los bultos; después se entregan cliente por cliente.
            </p>
        </div>

        @if ($guias->isEmpty())
            <section class="rounded-2xl bg-white p-12 text-center shadow-sm ring-1 ring-slate-200">
                <h3 class="text-lg font-bold text-slate-900">No hay guías en curso</h3>
                <p class="mt-1 text-sm text-slate-500">Cuando la oficina cree una guía{{ $esChofer ? ' para ti' : '' }}, aparece aquí.</p>
            </section>
        @else
            <ul class="grid gap-3 md:grid-cols-2">
                @foreach ($guias as $guia)
                    @php
                        $cargaCompleta = $guia->bultos > 0 && (int) $guia->cargados === (int) $guia->bultos;
                    @endphp
                    <li class="space-y-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-xl font-black text-slate-900">Guía #{{ $guia->id }}</p>
                                <p class="text-sm font-semibold text-slate-700">{{ $guia->ruta }}</p>
                                <p class="text-xs text-slate-500">
                                    {{ Carbon::parse($guia->fecha)->format('d-m-y H:i') }} · {{ $guia->nomchofer }}
                                    @if ($guia->unidad)
                                        · {{ $guia->unidad }}
                                    @endif
                                </p>
                            </div>
                            <x-estado-guia :estado="$guia->estado" />
                        </div>

                        <dl class="grid grid-cols-2 gap-2 text-center">
                            <div class="rounded-xl bg-slate-50 p-2 ring-1 ring-slate-200">
                                <dt class="text-xs font-semibold text-slate-500">Cargados</dt>
                                <dd class="text-lg font-black tabular-nums">{{ $guia->cargados }} / {{ $guia->bultos }}</dd>
                            </div>
                            <div class="rounded-xl bg-slate-50 p-2 ring-1 ring-slate-200">
                                <dt class="text-xs font-semibold text-slate-500">Entregados</dt>
                                <dd class="text-lg font-black tabular-nums">{{ $guia->entregados }} / {{ $guia->bultos }}</dd>
                            </div>
                        </dl>

                        <div class="grid grid-cols-2 gap-2">
                            @if ($puedeCargar)
                                <a href="{{ route('despacho.show', [$guia->id, 'carga']) }}"
                                   class="rounded-xl px-4 py-3 text-center text-sm font-bold {{ $cargaCompleta ? 'bg-white text-slate-600 ring-1 ring-slate-300' : 'bg-primary text-white hover:bg-primary-dark' }}">Cargar</a>
                            @endif
                            @if ($puedeDescargar)
                                @if ($cargaCompleta)
                                    <a href="{{ route('despacho.show', [$guia->id, 'descarga']) }}" class="rounded-xl bg-emerald-600 px-4 py-3 text-center text-sm font-bold text-white hover:bg-emerald-700">Entregar</a>
                                @else
                                    <span class="rounded-xl px-4 py-3 text-center text-sm font-bold text-slate-400 ring-1 ring-slate-200" title="Primero se cargan todos los bultos">Entregar</span>
                                @endif
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</x-layouts.app>
