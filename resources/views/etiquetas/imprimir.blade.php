@php
    use App\Services\Etiquetas\EtiquetasService;
    use App\Support\CodigosImpresion;

    ['ancho' => $ancho, 'alto' => $alto, 'nombre' => $nombreFormato] = $formato;
    $drogueria = $cfg?->nombre ?: 'SIDES';
    $qr = CodigosImpresion::qr((string) $pedido->codcli);
    $entrega = $cfg?->mostrarEntrega ? \Illuminate\Support\Str::limit((string) $pedido->entrega, 180, '') : '';
    $resumen = "Pedido #{$pedido->id} · {$bultos} ".($bultos === 1 ? 'etiqueta' : 'etiquetas')." de {$nombreFormato}. "
        .'Al imprimir elige la impresora de etiquetas y los márgenes en «Ninguno».';
@endphp

<x-layouts.impresion :titulo="'Etiquetas pedido #'.$pedido->id" :pagina="$ancho.'mm '.$alto.'mm'" :volver="$volver" :resumen="$resumen" :auto-imprimir="$autoImprimir">
    <x-slot:estilos>
        .etiqueta { --k: {{ round($alto / 80, 3) }}; width: {{ $ancho }}mm; height: {{ $alto }}mm; padding: calc(3mm * var(--k)) calc(3.5mm * var(--k)); display: flex; flex-direction: column; gap: calc(1.3mm * var(--k)); font-size: calc(3mm * var(--k)); line-height: 1.15; }
        .cabecera { display: flex; justify-content: space-between; align-items: flex-start; gap: 3mm; padding-bottom: calc(1.2mm * var(--k)); border-bottom: .4mm solid #000; }
        .drogueria { font-size: calc(4.2mm * var(--k)); font-weight: 900; text-transform: uppercase; }
        .rotulo { display: block; font-size: calc(2.4mm * var(--k)); font-weight: 700; letter-spacing: .02em; }
        .qr { flex: none; width: calc(13mm * var(--k)); height: calc(13mm * var(--k)); }
        .qr svg { display: block; width: 100%; height: 100%; }
        .cliente { flex: 1; min-height: 0; }
        .nombre, .entrega { display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 2; overflow: hidden; }
        .nombre { font-size: calc(5mm * var(--k)); font-weight: 900; }
        .entrega { margin-top: calc(.6mm * var(--k)); font-size: calc(2.8mm * var(--k)); }
        .datos { display: grid; grid-template-columns: 1.2fr 1fr 1.3fr; gap: 2mm; padding-top: calc(1.2mm * var(--k)); border-top: .4mm solid #000; }
        .valor { display: block; overflow: hidden; font-size: calc(4.6mm * var(--k)); font-weight: 900; white-space: nowrap; text-overflow: ellipsis; }
        .pie { display: grid; grid-template-columns: auto 1fr; align-items: end; gap: 4mm; }
        .pedido { display: block; font-size: calc(9mm * var(--k)); font-weight: 900; line-height: 1; }
        .barras { min-width: 0; text-align: center; font-size: calc(2.8mm * var(--k)); font-weight: 700; }
        .barras svg { display: block; width: 100%; height: calc(11mm * var(--k)); }
    </x-slot:estilos>

    @for ($bulto = 1; $bulto <= $bultos; $bulto++)
        @php $codigo = EtiquetasService::codigo((int) $pedido->id, $bulto); @endphp
        <article class="hoja etiqueta">
            <header class="cabecera">
                <div>
                    <p class="drogueria">{{ $drogueria }}</p>
                    <p><span class="rotulo">CÓDIGO CLIENTE</span>{{ $pedido->codcli }}</p>
                </div>
                <div class="qr">{!! $qr !!}</div>
            </header>

            <div class="cliente">
                <p class="nombre">{{ $pedido->nomcli }}</p>
                @if ($entrega !== '')
                    <p class="entrega">{{ $entrega }}</p>
                @endif
            </div>

            <div class="datos">
                <p><span class="rotulo">BULTO</span><span class="valor">{{ $bulto }} de {{ $bultos }}</span></p>
                <p><span class="rotulo">FECHA</span><span class="valor">{{ now()->format('d-m-Y') }}</span></p>
                <p><span class="rotulo">RUTA</span><span class="valor">{{ $pedido->ruta ?: 'N/A' }}</span></p>
            </div>

            <footer class="pie">
                <p><span class="rotulo">PEDIDO</span><span class="pedido">{{ $pedido->id }}</span></p>
                <div class="barras">{!! CodigosImpresion::barras($codigo) !!}{{ $codigo }}</div>
            </footer>
        </article>
    @endfor
</x-layouts.impresion>
