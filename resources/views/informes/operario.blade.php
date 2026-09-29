@php
    use App\Services\Informes\InformesService;
    use App\Support\Duracion;

    $inactividad = $vista === 'inactividad';
    $conTipo = ! $inactividad && $tipo === 'picking';
    $fechas = ['desde' => $desde->format('Y-m-d'), 'hasta' => $hasta->format('Y-m-d')];
    $numero = fn ($valor) => number_format((int) $valor, 0, ',', '.');
@endphp

<x-layouts.app :titulo="$operario->name">
    <div class="mx-auto max-w-6xl space-y-4">
        @include('informes.partials.encabezado', [
            'titulo' => $operario->name,
            'accion' => route('informes.operario', [$tipo, $vista, $operario->id]),
        ])

        <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-sm text-slate-500">
                {{ InformesService::textos($tipo, $vista)['titulo'] }} · {{ $operario->email }} ·
                del {{ $desde->format('d-m-Y') }} al {{ $hasta->format('d-m-Y') }} ·
                {{ $numero($registros->total()) }} {{ $registros->total() === 1 ? 'registro' : 'registros' }}
            </p>
            <div class="flex gap-2">
                <a href="{{ route('informes.reporte', [$tipo, $vista, ...$fechas]) }}"
                   class="rounded-xl bg-white px-3 py-2 text-sm font-semibold text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">Volver al ranking</a>
                @if ($registros->total() > 0)
                    <a href="{{ route('informes.operario.excel', [$tipo, $vista, $operario->id, ...$fechas]) }}"
                       class="flex items-center gap-1.5 rounded-xl bg-emerald-600 px-3 py-2 text-sm font-bold text-white hover:bg-emerald-700">
                        <svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! \App\Support\IconosSvg::path('download') !!}</svg>
                        Descargar Excel
                    </a>
                @endif
            </div>
        </div>

        @if ($inactividad)
            <p class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-amber-200">
                Si un tiempo muerto tiene justificación (almuerzo, reunión, falta de mercancía), puedes quitarlo
                del informe con «Quitar». Se borra solo ese registro y no se puede deshacer.
            </p>
        @endif

        @if ($registros->isEmpty())
            <section class="rounded-2xl bg-white p-12 text-center shadow-sm ring-1 ring-slate-200">
                <h3 class="text-lg font-bold text-slate-900">Sin registros en estas fechas</h3>
            </section>
        @else
            <section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
                <x-tabla-desplazable etiqueta="Registros del operario">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-bold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th scope="col" class="px-4 py-3">Fecha</th>
                                <th scope="col" class="px-4 py-3">Pedido</th>
                                <th scope="col" class="px-4 py-3">Cliente</th>
                                @if ($inactividad)
                                    <th scope="col" class="px-4 py-3">Pedido anterior</th>
                                @endif
                                <th scope="col" class="px-4 py-3 text-right">Unidades</th>
                                <th scope="col" class="px-4 py-3 text-right">Renglones</th>
                                <th scope="col" class="px-4 py-3 text-right">{{ $inactividad ? 'Tiempo inactivo' : 'Tiempo' }}</th>
                                @if ($conTipo)
                                    <th scope="col" class="px-4 py-3">Tipo</th>
                                @endif
                                @if ($inactividad)
                                    <th scope="col" class="px-4 py-3"><span class="sr-only">Acciones</span></th>
                                @endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 tabular-nums">
                            @foreach ($registros as $registro)
                                <tr class="hover:bg-slate-50">
                                    <td class="whitespace-nowrap px-4 py-3">{{ \Illuminate\Support\Carbon::parse($registro->fecha)->format('d-m-Y H:i') }}</td>
                                    <td class="px-4 py-3 font-bold text-slate-900">#{{ $registro->id_pedido }}</td>
                                    <td class="max-w-72 truncate px-4 py-3 text-slate-600" title="{{ $registro->nomcli }}">{{ $registro->nomcli }}</td>
                                    @if ($inactividad)
                                        <td class="px-4 py-3 text-slate-600">{{ $registro->id_pedido_anterior ? '#'.$registro->id_pedido_anterior : '—' }}</td>
                                    @endif
                                    <td class="px-4 py-3 text-right">{{ $numero($registro->numund) }}</td>
                                    <td class="px-4 py-3 text-right">{{ $numero($registro->numren) }}</td>
                                    <td class="px-4 py-3 text-right font-bold text-slate-900">{{ Duracion::texto($registro->tiempo) }}</td>
                                    @if ($conTipo)
                                        <td class="px-4 py-3 text-slate-600">{{ InformesService::DESCRIPCIONES[$registro->descripcion] ?? $registro->descripcion }}</td>
                                    @endif
                                    @if ($inactividad)
                                        <td class="px-4 py-3 text-right">
                                            <form method="POST" action="{{ route('informes.inactividad.destroy', [$tipo, $registro->id]) }}"
                                                  onsubmit="return confirm(@js('¿Quitar '.Duracion::texto($registro->tiempo).' de inactividad antes del pedido #'.$registro->id_pedido.'? No se puede deshacer.'))">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="font-semibold text-rose-700 hover:underline">Quitar</button>
                                            </form>
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-tabla-desplazable>
            </section>

            @if ($registros->hasPages())
                <div class="rounded-2xl bg-white px-4 py-3 shadow-sm ring-1 ring-slate-200">{{ $registros->links() }}</div>
            @endif
        @endif
    </div>
</x-layouts.app>
