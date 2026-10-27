{{-- Pestañas del Almacén 3D. Las que cambian el almacén: permiso de ubicaciones o quien administra la droguería. --}}
@php
    $usuario = auth()->user();
    $pestanas = [
        'almacen.index' => 'Ver almacén',
        'almacen.armar' => 'Armar almacén',
        'almacen.ubicaciones' => 'Registrar ubicaciones',
        'almacen.acomodo' => 'Ajustes avanzados',
    ];
@endphp

@if (\App\Support\MenuSides::puede($usuario, $usuario->cfg, 'ubicaciones'))
    <nav class="flex gap-1 overflow-x-auto rounded-xl bg-slate-200 p-1" aria-label="Almacén">
        @foreach ($pestanas as $ruta => $etiqueta)
            <a href="{{ route($ruta) }}" @if ($activa === $ruta) aria-current="page" @endif
               class="flex-1 whitespace-nowrap rounded-lg px-3 py-2 text-center text-sm font-bold {{ $activa === $ruta ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900' }}">{{ $etiqueta }}</a>
        @endforeach
    </nav>
@endif
