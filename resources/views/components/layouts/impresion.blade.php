@props(['titulo', 'pagina', 'volver', 'resumen' => '', 'autoImprimir' => false])

{{-- Página de impresión (etiquetas, ticket): sin menú, con el tamaño de papel en @page. --}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="{{ auth()->user()?->cfg?->urlIcono() ?: asset('img/favicon-sides.png') }}">
    <title>{{ $titulo }} · SIDES</title>
    <style>
        @page { size: {{ $pagina }}; margin: 0; }
        * { box-sizing: border-box; }
        html, body { margin: 0; }
        body { font-family: Arial, Helvetica, sans-serif; color: #000; background: #e2e8f0; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        p { margin: 0; }
        .acciones { position: sticky; top: 0; z-index: 1; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px 16px; padding: 12px 16px; background: #0f172a; color: #fff; font-size: 14px; line-height: 1.4; }
        .acciones .aviso { color: #86efac; font-weight: 700; }
        .acciones .botones { display: flex; gap: 8px; }
        .acciones a, .acciones button { border: 0; border-radius: 10px; padding: 9px 14px; font: inherit; font-weight: 700; text-decoration: none; cursor: pointer; }
        .acciones a { background: #334155; color: #fff; }
        .acciones button { background: #fff; color: #0f172a; }
        .hojas { display: flex; flex-direction: column; align-items: center; gap: 16px; padding: 16px; overflow-x: auto; }
        .hoja { flex: none; background: #fff; box-shadow: 0 1px 4px rgb(0 0 0 / .25); overflow: hidden; }
        @media print {
            body { background: #fff; }
            .acciones { display: none; }
            .hojas { display: block; padding: 0; overflow: visible; }
            .hoja { box-shadow: none; break-after: page; }
            .hoja:last-child { break-after: auto; }
        }
        {!! $estilos ?? '' !!}
    </style>
</head>
<body>
    {{--
        Filtro "logo-tinta" para impresoras de un solo color (etiquetas, ticket): deja el logo en
        negro puro. Lo oscuro queda negro y a lo claro (un círculo gris, un fondo de color), que
        esas impresoras no marcan, se le dibuja solo el contorno para que no desaparezca.
    --}}
    <svg width="0" height="0" style="position: absolute" aria-hidden="true">
        <filter id="logo-tinta" color-interpolation-filters="sRGB" x="0" y="0" width="100%" height="100%">
            {{-- Cuánta tinta lleva cada punto: 0 = blanco o transparente, 1 = negro. --}}
            <feColorMatrix in="SourceGraphic" type="matrix" values="0 0 0 0 0  0 0 0 0 0  0 0 0 0 0  -0.2126 -0.7152 -0.0722 1 0" result="tinta"/>
            <feComponentTransfer in="tinta" result="algo"><feFuncA type="linear" slope="20" intercept="-1.2"/></feComponentTransfer>
            <feComponentTransfer in="tinta" result="oscuro"><feFuncA type="linear" slope="20" intercept="-9.5"/></feComponentTransfer>
            <feMorphology in="algo" operator="erode" radius="1.2" result="dentro"/>
            <feComposite in="algo" in2="dentro" operator="out" result="contorno"/>
            <feMerge><feMergeNode in="contorno"/><feMergeNode in="oscuro"/></feMerge>
        </filter>
    </svg>
    <div class="acciones">
        <div>
            @if (session('mensaje'))
                <p class="aviso">{{ session('mensaje') }}</p>
            @endif
            <p>{{ $resumen }}</p>
        </div>
        <div class="botones">
            <a href="{{ $volver }}">Volver</a>
            <button type="button" onclick="window.print()">Imprimir</button>
        </div>
    </div>

    <main class="hojas">{{ $slot }}</main>

    @if ($autoImprimir)
        <script>window.addEventListener('load', () => setTimeout(() => window.print(), 250));</script>
    @endif
</body>
</html>
