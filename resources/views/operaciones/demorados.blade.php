@php
    use App\Services\Informes\OperacionesService;
    use App\Support\MenuSides;

    $t = fn (?int $s) => OperacionesService::texto($s);
    $fechas = ['desde' => $desde->format('Y-m-d'), 'hasta' => $hasta->format('Y-m-d')];
    $filtros = [...$fechas, 'minutos' => $minutos, ...($etapa ? ['etapa' => $etapa] : [])];
    $etapas = $conPacking ? ['aceptar', 'picking', 'packing'] : ['aceptar', 'picking'];
    $verPedidos = MenuSides::puede(auth()->user(), auth()->user()->cfg, 'pedidos');
    $campo = 'mt-1 block rounded-xl border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-primary focus:ring-primary';

    $opciones = '<option value="">Cualquiera</option>';
    foreach ($etapas as $e) {
        $opciones .= '<option value="'.$e.'"'.($etapa === $e ? ' selected' : '').'>'.e(OperacionesService::NOMBRES_ETAPA[$e]).'</option>';
    }
    $extra = '<label class="text-xs font-semibold text-slate-600">Etapa<select name="etapa" class="'.$campo.'">'.$opciones.'</select></label>'
        .'<label class="text-xs font-semibold text-slate-600">Más de (minutos)<input type="number" name="minutos" min="1" max="1440" value="'.$minutos.'" required class="'.$campo.' w-28"></label>';
@endphp

<x-layouts.app titulo="Pedidos demorados">
    <div class="mx-auto max-w-6xl space-y-4">
        @include('operaciones.partials.encabezado', [
            'actual' => 'demorados',
            'titulo' => 'Pedidos demorados',
            'detalle' => 'Pedidos que pasaron más de '.$minutos.' minutos en '.($etapa ? OperacionesService::NOMBRES_ETAPA[$etapa] : 'alguna etapa').'. Los que siguen en esa etapa cuentan hasta ahora y salen «en curso».',
            'accion' => route('operaciones.demorados'),
            'extra' => $extra,
            'excel' => route('operaciones.demorados.excel', $filtros),
        ])

        <section aria-label="Totales" class="grid grid-cols-2 gap-3 {{ $conPacking ? 'lg:grid-cols-5' : 'lg:grid-cols-4' }}">
            <div class="rounded-2xl px-4 py-3 shadow-sm ring-1 {{ $total > 0 ? 'bg-rose-50 ring-rose-200' : 'bg-white ring-slate-200' }}">
                <div class="text-[11px] font-bold uppercase tracking-wider {{ $total > 0 ? 'text-rose-700' : 'text-slate-500' }}">Pedidos demorados</div>
                <div class="mt-0.5 text-2xl font-black tabular-nums {{ $total > 0 ? 'text-rose-700' : 'text-slate-900' }}">{{ number_format($total, 0, ',', '.') }}</div>
            </div>
            <div class="rounded-2xl bg-white px-4 py-3 shadow-sm ring-1 ring-slate-200">
                <div class="text-[11px] font-bold uppercase tracking-wider text-slate-500">En curso ahora</div>
                <div class="mt-0.5 text-2xl font-black tabular-nums text-slate-900">{{ $enCurso }}</div>
            </div>
            @foreach ($etapas as $e)
                <div class="rounded-2xl bg-white px-4 py-3 shadow-sm ring-1 ring-slate-200">
                    <div class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Se demoró en {{ strtolower(OperacionesService::NOMBRES_ETAPA[$e]) }}</div>
                    <div class="mt-0.5 text-2xl font-black tabular-nums text-slate-900">{{ $porEtapa[$e] ?? 0 }}</div>
                </div>
            @endforeach
        </section>

        @if ($total === 0)
            <section class="rounded-2xl bg-white p-12 text-center shadow-sm ring-1 ring-slate-200">
                <h3 class="text-lg font-bold text-slate-900">Ningún pedido se demoró</h3>
                <p class="mt-1 text-sm text-slate-500">Del {{ $desde->format('d-m-Y') }} al {{ $hasta->format('d-m-Y') }} ningún pedido pasó de {{ $minutos }} minutos en esa etapa.</p>
            </section>
        @else
            <section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
                <x-tabla-desplazable etiqueta="Pedidos demorados">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-bold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th scope="col" class="px-4 py-3">Pedido</th>
                                <th scope="col" class="px-4 py-3">Cliente</th>
                                <th scope="col" class="px-4 py-3">Se demoró en</th>
                                @foreach ($etapas as $e)
                                    <th scope="col" class="px-4 py-3 text-right">{{ OperacionesService::NOMBRES_ETAPA[$e] }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($filas as $fila)
                                <tr class="hover:bg-slate-50">
                                    <td class="whitespace-nowrap px-4 py-2.5 align-top">
                                        @if ($verPedidos)
                                            <a href="{{ route('pedidos.show', $fila['id']) }}" class="font-bold text-primary-ink hover:underline">#{{ $fila['id'] }}</a>
                                        @else
                                            <span class="font-bold text-slate-800">#{{ $fila['id'] }}</span>
                                        @endif
                                        <div class="text-xs text-slate-500">Llegó {{ $fila['recibido']->format('d-m-y H:i') }}</div>
                                        <x-estado-pedido :estado="$fila['estado']" />
                                    </td>
                                    <td class="px-4 py-2.5 align-top">
                                        <div class="font-semibold text-slate-800">{{ $fila['nomcli'] }}</div>
                                        <div class="text-xs text-slate-500">{{ $fila['codcli'] }}@if ($fila['ruta']) · {{ $fila['ruta'] }}@endif</div>
                                    </td>
                                    <td class="px-4 py-2.5 align-top">
                                        <div class="font-black text-rose-700">{{ OperacionesService::NOMBRES_ETAPA[$fila['etapa']] }} · {{ $t($fila['segundos']) }}</div>
                                        @if ($fila['enCurso'])
                                            <span class="mt-0.5 inline-block rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-bold text-amber-800">En curso</span>
                                        @endif
                                        @if ($fila['responsable'])
                                            <div class="text-xs text-slate-500">{{ $fila['etapa'] === 'picking' ? 'Picking de' : 'Packing de' }} {{ $fila['responsable'] }}</div>
                                        @endif
                                    </td>
                                    @foreach ($etapas as $e)
                                        @php $etapaFila = $fila['etapas'][$e]; @endphp
                                        <td class="whitespace-nowrap px-4 py-2.5 text-right align-top tabular-nums {{ $e === $fila['etapa'] ? 'font-bold text-rose-700' : 'text-slate-600' }}">
                                            {{ $t($etapaFila['segundos']) }}@if ($etapaFila['enCurso'])<span class="text-amber-700">…</span>@endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-tabla-desplazable>
            </section>

            {{ $filas->links() }}
        @endif
    </div>
</x-layouts.app>
