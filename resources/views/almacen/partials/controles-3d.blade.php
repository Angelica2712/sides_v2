{{--
    Botones para moverse en el visor 3D (sobre el visor, abajo a la derecha). El componente Alpine
    que lo incluye tiene navegar3d(accion), que llama a visor.navegar de resources/js/almacen-3d.js.
--}}
@php
    $boton = 'flex size-9 items-center justify-center rounded-lg bg-white/90 text-slate-700 shadow ring-1 ring-slate-300 hover:bg-white active:bg-slate-100';
    $icono = 'size-5';
@endphp

<div class="absolute bottom-2 right-2 flex items-end gap-1.5" role="group" aria-label="Moverse en el almacén">
    <div class="flex flex-col gap-1.5">
        <button type="button" @click="navegar3d('acercar')" class="{{ $boton }} text-lg font-bold" aria-label="Acercar" title="Acercar">+</button>
        <button type="button" @click="navegar3d('alejar')" class="{{ $boton }} text-lg font-bold" aria-label="Alejar" title="Alejar">−</button>
    </div>
    <div class="grid grid-cols-3 gap-1">
        <button type="button" @click="navegar3d('girarIzquierda')" class="{{ $boton }}" aria-label="Girar a la izquierda" title="Girar a la izquierda">
            <svg class="{{ $icono }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8" /><path d="M3 3v5h5" /></svg>
        </button>
        <button type="button" @click="navegar3d('adelante')" class="{{ $boton }}" aria-label="Avanzar" title="Avanzar">
            <svg class="{{ $icono }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m18 15-6-6-6 6" /></svg>
        </button>
        <button type="button" @click="navegar3d('girarDerecha')" class="{{ $boton }}" aria-label="Girar a la derecha" title="Girar a la derecha">
            <svg class="{{ $icono }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8" /><path d="M21 3v5h-5" /></svg>
        </button>
        <button type="button" @click="navegar3d('izquierda')" class="{{ $boton }}" aria-label="Ir a la izquierda" title="Ir a la izquierda">
            <svg class="{{ $icono }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6" /></svg>
        </button>
        <button type="button" @click="navegar3d('atras')" class="{{ $boton }}" aria-label="Retroceder" title="Retroceder">
            <svg class="{{ $icono }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg>
        </button>
        <button type="button" @click="navegar3d('derecha')" class="{{ $boton }}" aria-label="Ir a la derecha" title="Ir a la derecha">
            <svg class="{{ $icono }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6" /></svg>
        </button>
    </div>
</div>
