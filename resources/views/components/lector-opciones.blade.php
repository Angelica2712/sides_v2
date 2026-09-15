{{-- Opciones del lector de códigos. El x-data que lo contiene debe incluir lector() (resources/js/lector.js). --}}
<div {{ $attributes->merge(['class' => 'flex flex-wrap gap-2']) }}>
    <button type="button" @click="alternarTeclado()" :aria-pressed="teclado.toString()"
            :class="teclado ? 'bg-primary text-white ring-primary' : 'bg-white text-slate-600 ring-slate-300 hover:bg-slate-50'"
            class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-bold ring-1">
        <svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! \App\Support\IconosSvg::path('keyboard') !!}</svg>
        <span x-text="teclado ? 'Ocultar teclado' : 'Escribir a mano'"></span>
    </button>
    <button type="button" @click="alternarSonido()" :aria-pressed="conSonido.toString()"
            class="inline-flex items-center gap-1.5 rounded-full bg-white px-3 py-1.5 text-xs font-bold text-slate-600 ring-1 ring-slate-300 hover:bg-slate-50">
        <svg x-show="conSonido" class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! \App\Support\IconosSvg::path('volume') !!}</svg>
        <svg x-show="!conSonido" x-cloak class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! \App\Support\IconosSvg::path('volumex') !!}</svg>
        <span x-text="conSonido ? 'Sonido activo' : 'Sin sonido'"></span>
    </button>
</div>
