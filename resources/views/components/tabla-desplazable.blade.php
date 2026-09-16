@props(['etiqueta'])

{{-- Tabla ancha con la barra de desplazamiento horizontal también arriba (resources/js/tabla-desplazable.js). --}}
<div x-data="tablaDesplazable" {{ $attributes }}>
    <div x-ref="barra" x-show="visible" @scroll="desdeBarra()" aria-hidden="true"
         class="overflow-x-auto overflow-y-hidden border-b border-slate-200 bg-slate-50">
        <div class="h-2" :style="{ width: ancho + 'px' }"></div>
    </div>

    {{-- relative: sin él, un texto sr-only de la última columna escapa del scroll y estira la página. --}}
    <div x-ref="caja" @scroll="desdeCaja()" tabindex="0" role="region" aria-label="{{ $etiqueta }}"
         class="relative overflow-x-auto focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary">
        {{ $slot }}
    </div>
</div>
