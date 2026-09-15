@php
    $unidades = $renglones->sum(fn ($renglon) => max((int) $renglon->cantdesp, 0));
    // Mismo cálculo del legacy (rptticket): el largo del papel crece con los renglones.
    $alto = (int) ceil(55 + 4.5 * $renglones->count());
    $fecha = $pedido->fecha ? \Illuminate\Support\Carbon::parse($pedido->fecha)->format('d-m-Y H:i') : '—';
@endphp

<x-layouts.impresion :titulo="'Ticket pedido #'.$pedido->id" :pagina="'77mm '.$alto.'mm'" :volver="$volver"
                     :resumen="'Ticket del pedido #'.$pedido->id.' · '.$renglones->count().' renglones. Elige la impresora de tickets.'">
    <x-slot:estilos>
        .ticket { width: 77mm; min-height: {{ $alto }}mm; padding: 3mm 3.5mm; font-size: 2.9mm; line-height: 1.35; }
        .ticket h1 { margin: 0; font-size: 3.4mm; text-align: center; text-transform: uppercase; }
        .centro { text-align: center; }
        .fila { display: flex; justify-content: space-between; gap: 2mm; }
        .fila span:first-child { overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
        hr { margin: 1.8mm 0; border: 0; border-top: .3mm dashed #000; }
    </x-slot:estilos>

    <article class="hoja ticket">
        <h1>{{ $cfg?->nombre ?: 'SIDES' }}</h1>
        <p class="centro"><strong>{{ $pedido->nomcli }}</strong></p>
        <p class="centro">Fecha: {{ $fecha }}</p>
        <hr>
        <p>Pedido: {{ $pedido->id }}</p>
        <p>Despachador: {{ $pedido->despachador ?: '—' }}</p>
        <p>Embalador: {{ $pedido->embalador ?: '—' }}</p>
        <p class="fila"><span>Bultos: {{ max(1, (int) $pedido->cantBultos) }}</span><strong>Renglones: {{ $renglones->count() }}</strong></p>
        <hr>
        @foreach ($renglones as $renglon)
            <p class="fila"><span>{{ $renglon->codprod }} {{ $renglon->desprod }}</span><span>{{ max((int) $renglon->cantdesp, 0) }}</span></p>
        @endforeach
        <hr>
        <p class="fila"><strong>Unidades totales:</strong><strong>{{ $unidades }}</strong></p>
    </article>
</x-layouts.impresion>
