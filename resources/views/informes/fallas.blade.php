@php
    use App\Services\Informes\FallasService;
    use App\Support\MenuSides;

    $fechas = ['desde' => $desde->format('Y-m-d'), 'hasta' => $hasta->format('Y-m-d')];
    $filtros = [...$fechas, ...($buscar !== '' ? ['buscar' => $buscar] : [])];
    $numero = fn ($valor) => number_format((int) $valor, 0, ',', '.');
    $campo = 'mt-1 block rounded-xl border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-primary focus:ring-primary';
    $porProducto = $vista === 'producto';
    $verPedidos = MenuSides::puede(auth()->user(), auth()->user()->cfg, 'pedidos');
    $cumplido = (int) $totales->solicitado > 0 ? round((int) $totales->despachado / (int) $totales->solicitado * 100) : 0;

    $tarjetas = [
        ['etiqueta' => 'Productos con falla', 'valor' => $numero($totales->productos)],
        ['etiqueta' => 'Pedidos afectados', 'valor' => $numero($totales->pedidos)],
        ['etiqueta' => 'Unidades pedidas', 'valor' => $numero($totales->solicitado)],
        ['etiqueta' => 'Unidades despachadas', 'valor' => $numero($totales->despachado)],
        ['etiqueta' => 'Unidades que faltaron', 'valor' => $numero($totales->faltante), 'alerta' => true],
    ];
@endphp

<x-layouts.app titulo="Fallas">
    <div class="mx-auto max-w-6xl space-y-4">
        @include('informes.partials.pestanas', ['fechas' => $fechas, 'actual' => 'fallas'])

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div class="min-w-0">
                <h2 class="text-xl font-extrabold text-slate-900">Fallas</h2>
                <p class="text-sm text-slate-500">Productos que las farmacias pidieron y se despacharon de menos, en pedidos con el despacho ya cerrado.</p>
            </div>

            <form method="GET" action="{{ route('informes.fallas') }}" class="flex flex-wrap items-end gap-2" role="search">
                <input type="hidden" name="vista" value="{{ $vista }}">
                <label class="text-xs font-semibold text-slate-600">
                    Buscar
                    <input type="search" name="buscar" value="{{ $buscar }}" placeholder="Producto, código, marca, cliente o pedido" class="{{ $campo }} w-64">
                </label>
                <label class="text-xs font-semibold text-slate-600">
                    Desde
                    <input type="date" name="desde" value="{{ $fechas['desde'] }}" required class="{{ $campo }}">
                </label>
                <label class="text-xs font-semibold text-slate-600">
                    Hasta
                    <input type="date" name="hasta" value="{{ $fechas['hasta'] }}" required class="{{ $campo }}">
                </label>
                <button type="submit" class="rounded-xl bg-primary px-4 py-2 text-sm font-bold text-on-primary hover:bg-primary-dark">Consultar</button>
            </form>
        </div>

        @if ($errors->any())
            <div role="alert" class="rounded-xl bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800 ring-1 ring-rose-200">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        <p class="text-sm text-slate-500">
            Pedidos procesados del {{ $desde->format('d-m-Y') }} al {{ $hasta->format('d-m-Y') }}@if ($buscar !== ''), que coinciden con «{{ $buscar }}»@endif.
        </p>

        @if ((int) $totales->renglones === 0)
            <section class="rounded-2xl bg-white p-12 text-center shadow-sm ring-1 ring-slate-200">
                <h3 class="text-lg font-bold text-slate-900">No hay fallas en estas fechas</h3>
                <p class="mt-1 text-sm text-slate-500">
                    Todo lo pedido se despachó completo, o los pedidos de estas fechas todavía no terminan picking y packing.
                </p>
            </section>
        @else
            <section aria-label="Totales" class="grid grid-cols-2 gap-3 lg:grid-cols-5">
                @foreach ($tarjetas as $tarjeta)
                    <div class="rounded-2xl px-4 py-3 shadow-sm ring-1 {{ ($tarjeta['alerta'] ?? false) ? 'bg-rose-50 ring-rose-200' : 'bg-white ring-slate-200' }}">
                        <div class="text-[11px] font-bold uppercase tracking-wider {{ ($tarjeta['alerta'] ?? false) ? 'text-rose-700' : 'text-slate-500' }}">{{ $tarjeta['etiqueta'] }}</div>
                        <div class="mt-0.5 text-2xl font-black tabular-nums {{ ($tarjeta['alerta'] ?? false) ? 'text-rose-700' : 'text-slate-900' }}">{{ $tarjeta['valor'] }}</div>
                    </div>
                @endforeach
            </section>
            <p class="text-xs text-slate-500">
                De los renglones con falla se despachó el {{ $cumplido }} % de lo pedido. Los renglones que salieron completos no entran en este informe.
            </p>

            <section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-4 py-3">
                    <div class="flex gap-1 rounded-xl bg-slate-100 p-1" role="group" aria-label="Cómo ver las fallas">
                        @foreach (FallasService::VISTAS as $clave => $titulo)
                            <a href="{{ route('informes.fallas', [...$filtros, 'vista' => $clave]) }}" @if ($vista === $clave) aria-current="true" @endif
                               class="rounded-lg px-3 py-1.5 text-sm font-bold {{ $vista === $clave ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900' }}">{{ $titulo }}</a>
                        @endforeach
                    </div>
                    <a href="{{ route('informes.fallas.excel', $filtros) }}"
                       class="flex items-center gap-1.5 rounded-xl bg-emerald-600 px-3 py-2 text-sm font-bold text-white hover:bg-emerald-700">
                        <svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! \App\Support\IconosSvg::path('download') !!}</svg>
                        Descargar Excel
                    </a>
                </div>

                <x-tabla-desplazable etiqueta="Fallas {{ $porProducto ? 'por producto' : 'por pedido' }}">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-bold uppercase tracking-wide text-slate-500">
                            <tr>
                                @unless ($porProducto)
                                    <th scope="col" class="px-4 py-3">Pedido</th>
                                    <th scope="col" class="px-4 py-3">Cliente</th>
                                @endunless
                                <th scope="col" class="px-4 py-3">Producto</th>
                                @if ($porProducto)
                                    <th scope="col" class="px-4 py-3">Marca</th>
                                    <th scope="col" class="px-4 py-3 text-right">Pedidos</th>
                                @endif
                                <th scope="col" class="px-4 py-3 text-right">Pidieron</th>
                                <th scope="col" class="px-4 py-3 text-right">Despachado</th>
                                <th scope="col" class="px-4 py-3 text-right">Faltó</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($filas as $fila)
                                <tr class="hover:bg-slate-50">
                                    @unless ($porProducto)
                                        <td class="whitespace-nowrap px-4 py-3 align-top">
                                            @if ($verPedidos)
                                                <a href="{{ route('pedidos.show', $fila->id) }}" class="font-bold text-primary-ink hover:underline">#{{ $fila->id }}</a>
                                            @else
                                                <span class="font-bold text-slate-800">#{{ $fila->id }}</span>
                                            @endif
                                            <div class="text-xs text-slate-500">{{ $fila->fecprocesado ? \Illuminate\Support\Carbon::parse($fila->fecprocesado)->format('d-m-y H:i') : '—' }}</div>
                                        </td>
                                        <td class="px-4 py-3 align-top">
                                            <div class="font-semibold text-slate-800">{{ $fila->nomcli }}</div>
                                            <div class="text-xs text-slate-500">{{ $fila->codcli }}@if ($fila->ruta) · {{ $fila->ruta }}@endif @if ($fila->despachador) · picking de {{ $fila->despachador }}@endif</div>
                                        </td>
                                    @endunless
                                    <td class="px-4 py-3 align-top">
                                        <div class="font-semibold text-slate-800">{{ $fila->desprod }}</div>
                                        <div class="text-xs text-slate-500">{{ $fila->codprod }}@if ($fila->barra) · {{ $fila->barra }}@endif @if (! $porProducto && $fila->marcamodelo) · {{ $fila->marcamodelo }}@endif</div>
                                    </td>
                                    @if ($porProducto)
                                        <td class="px-4 py-3 align-top text-slate-600">{{ $fila->marcamodelo ?: '—' }}</td>
                                        <td class="px-4 py-3 text-right align-top tabular-nums">
                                            <a href="{{ route('informes.fallas', [...$fechas, 'vista' => 'pedido', 'buscar' => $fila->codprod]) }}" class="font-semibold text-primary-ink hover:underline"
                                               title="Ver en qué pedidos faltó">{{ $numero($fila->pedidos) }}</a>
                                        </td>
                                    @endif
                                    <td class="px-4 py-3 text-right align-top tabular-nums">{{ $numero($fila->solicitado) }}</td>
                                    <td class="px-4 py-3 text-right align-top tabular-nums">{{ $numero($fila->despachado) }}</td>
                                    <td class="px-4 py-3 text-right align-top font-black tabular-nums text-rose-700">{{ $numero($fila->faltante) }}</td>
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
