@php
    use App\Support\CodigosImpresion;
    use App\Support\FechaSeped;
    use Illuminate\Support\Carbon;

    $fecha = fn ($valor) => $valor ? Carbon::parse($valor)->format('d-m-Y H:i') : '—';
@endphp

<x-layouts.impresion :titulo="'Guía #'.$guia->id" pagina="letter portrait" :volver="route('guias.show', $guia->id)"
                     :resumen="'Guía de despacho #'.$guia->id.' · '.$clientes->count().' clientes · '.$clientes->sum('bultos').' bultos. Papel carta.'">
    <x-slot:estilos>
        /* Una sola hoja que sigue en las páginas que haga falta. */
        .guia { width: 215.9mm; min-height: 279.4mm; padding: 12mm; font-size: 3.2mm; line-height: 1.35; overflow: visible; }
        .encabezado { display: flex; justify-content: space-between; align-items: flex-start; gap: 6mm; padding-bottom: 3mm; border-bottom: .5mm solid #000; }
        .encabezado h1 { margin: 0; font-size: 5.5mm; }
        .encabezado h2 { margin: 0; font-size: 4mm; }
        .datos { display: grid; grid-template-columns: auto auto; gap: .5mm 3mm; margin-top: 2mm; }
        .datos dt { font-weight: 700; }
        .datos dd { margin: 0; }
        .cliente { margin-top: 4mm; border: .3mm solid #000; break-inside: avoid; }
        .cliente-cabecera { display: grid; grid-template-columns: 16mm 1fr 62mm; gap: 3mm; padding: 2mm; align-items: start; }
        .cliente-cabecera svg { width: 16mm; height: 16mm; }
        .orden { font-size: 5mm; font-weight: 700; }
        .firma { height: 18mm; border: .3mm dashed #000; padding: 1mm; font-size: 2.4mm; text-align: center; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border-top: .3mm solid #000; padding: 1mm 2mm; text-align: left; }
        th { background: #e5e7eb; font-size: 2.8mm; }
        .num { text-align: right; }
        .totales { margin-top: 5mm; display: flex; justify-content: flex-end; gap: 8mm; font-size: 3.6mm; font-weight: 700; }
    </x-slot:estilos>

    <article class="hoja guia">
        <header class="encabezado">
            <div>
                <h1>{{ $cfg?->nombre ?: 'SIDES' }}</h1>
                <dl class="datos">
                    <dt>Ruta:</dt><dd>{{ $guia->ruta }}</dd>
                    @if ($guia->unidad)
                        <dt>Unidad:</dt><dd>{{ $guia->unidad }}</dd>
                    @endif
                    <dt>Chofer:</dt><dd>{{ $guia->nomchofer }}</dd>
                    @if ($guia->chof_aux_nom)
                        <dt>Auxiliar:</dt><dd>{{ $guia->chof_aux_nom }}</dd>
                    @endif
                </dl>
            </div>
            <div>
                <h2>Guía de despacho N° {{ $guia->id }}</h2>
                <dl class="datos">
                    <dt>Fecha guía:</dt><dd>{{ $fecha($guia->fecha) }}</dd>
                    <dt>Emisión:</dt><dd>{{ $emision->format('d-m-Y H:i') }}</dd>
                    @if ($guia->fecha_salida)
                        <dt>Salida:</dt><dd>{{ $fecha($guia->fecha_salida) }}</dd>
                    @endif
                </dl>
            </div>
        </header>

        @foreach ($clientes as $posicion => $cliente)
            <section class="cliente">
                <div class="cliente-cabecera">
                    {!! CodigosImpresion::qr($cliente->codcli) !!}
                    <div>
                        <p><span class="orden">{{ $posicion + 1 }}.</span> <strong>{{ $cliente->rif }} - {{ $cliente->nombre }}</strong></p>
                        <p>Código: {{ $cliente->codcli }}</p>
                        <p>{{ $cliente->direccion ?: 'Sin dirección de entrega en SEPED' }}</p>
                    </div>
                    <div class="firma">NOMBRE - FIRMA - SELLO</div>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Tipo</th>
                            <th>Pedido</th>
                            <th>Factura</th>
                            <th>Emisión</th>
                            <th class="num">Bultos</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($cliente->pedidos as $pedido)
                            <tr>
                                <td>{{ $pedido->datos?->tipedido ?: '—' }}</td>
                                <td>{{ $pedido->id }}</td>
                                <td>{{ $pedido->facturas->map(fn ($f) => trim($f->factnum.($f->nroctrol ? ' / '.$f->nroctrol : '')))->implode(', ') ?: '—' }}</td>
                                <td>{{ FechaSeped::mostrar($pedido->datos?->fecfacturado) }}</td>
                                <td class="num">{{ $pedido->bultos->count() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
        @endforeach

        <p class="totales">
            <span>Total clientes: {{ $clientes->count() }}</span>
            <span>Piezas: {{ $clientes->sum('bultos') }}</span>
        </p>
    </article>
</x-layouts.impresion>
