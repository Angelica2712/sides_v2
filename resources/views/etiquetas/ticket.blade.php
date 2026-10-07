@php
    use App\Support\FormatosEtiqueta;

    $unidades = $renglones->sum(fn ($renglon) => max((int) $renglon->cantdesp, 0));
    // El diseño es el del legacy (rptticket, 77 mm de ancho) y se escala al ancho del papel de la droguería.
    $ancho = FormatosEtiqueta::anchoTicket($cfg);
    $k = round($ancho / FormatosEtiqueta::TICKET_ANCHO, 3);
    // Mismo cálculo del legacy: el largo del papel crece con los renglones.
    $alto = (int) ceil((55 + 4.5 * $renglones->count()) * $k);
    $fecha = $pedido->fecha ? \Illuminate\Support\Carbon::parse($pedido->fecha)->format('d-m-Y H:i') : '—';
@endphp

<x-layouts.impresion :titulo="'Ticket pedido #'.$pedido->id" :pagina="$ancho.'mm '.$alto.'mm'" :volver="$volver"
                     :resumen="'Ticket del pedido #'.$pedido->id.' · '.$renglones->count().' renglones · papel de '.$ancho.' mm. Elige la impresora de tickets.'">
    <x-slot:estilos>
        .ticket { --k: {{ $k }}; width: {{ $ancho }}mm; min-height: {{ $alto }}mm; padding: calc(3mm * var(--k)) calc(3.5mm * var(--k)); font-size: calc(2.9mm * var(--k)); line-height: 1.35; }
        /* Logo en negro puro con contorno, igual que en las etiquetas: el ticket también sale en un solo color. */
        .ticket .logo { display: block; max-width: calc(50mm * var(--k)); max-height: calc(16mm * var(--k)); margin: 0 auto calc(1.5mm * var(--k)); object-fit: contain; filter: url(#logo-tinta); }
        .ticket h1 { margin: 0; font-size: calc(3.4mm * var(--k)); text-align: center; text-transform: uppercase; }
        .centro { text-align: center; }
        .fila { display: flex; justify-content: space-between; gap: 2mm; }
        .fila span:first-child { overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
        hr { margin: calc(1.8mm * var(--k)) 0; border: 0; border-top: .3mm dashed #000; }
    </x-slot:estilos>

    <article class="hoja ticket">
        @if ($cfg?->logo)
            <img class="logo" src="{{ $cfg->urlLogo() }}" alt="">
        @endif
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
