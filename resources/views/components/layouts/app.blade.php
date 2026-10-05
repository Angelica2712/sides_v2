@props(['titulo' => null])

@php
    $usuario = auth()->user();
    $cfg = $usuario->cfg;
    // Inicio va solo, arriba; el resto, agrupado por secciones (MenuSides::SECCIONES).
    $seccionesMenu = array_merge(
        ['' => [['etiqueta' => 'Inicio', 'ruta' => 'home', 'icono' => 'home']]],
        \App\Support\MenuSides::porSeccion($usuario, $cfg)
    );
    $nombreCorto = $cfg?->nomcorto ?: ($cfg?->nombre ?: 'SIDES');
    $logoDrogueria = $cfg?->urlLogo();
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <link rel="icon" type="image/png" href="{{ asset('img/favicon-sides.png') }}">

        <title>{{ $titulo ? $titulo.' · ' : '' }}SIDES {{ $nombreCorto }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @if ($temaDrogueria = \App\Support\TemaSides::estilo($usuario->codisb))
            <style>{!! $temaDrogueria !!}</style>
        @endif
    </head>
    <body class="font-sans antialiased bg-page text-slate-800">
        <div x-data="{ sidebarOpen: false, sidebarCollapsed: localStorage.getItem('sidesSidebarCollapsed') === 'true' }"
             x-init="$watch('sidebarCollapsed', v => localStorage.setItem('sidesSidebarCollapsed', v))"
             class="min-h-screen flex">

            <div x-show="sidebarOpen" x-cloak x-transition.opacity @click="sidebarOpen = false"
                 class="fixed inset-0 z-40 bg-slate-900/60 lg:hidden"></div>

            <aside style="width: 17rem"
                   :style="'width: ' + (sidebarCollapsed ? '78px' : '17rem')"
                   :class="{ 'translate-x-0!': sidebarOpen }"
                   class="fixed lg:sticky inset-y-0 lg:top-0 left-0 z-50 flex flex-col h-screen bg-sidebar border-r border-sidebar-border shadow-lg -translate-x-full lg:translate-x-0 transition-[width,transform] duration-300">

                <div class="shrink-0 min-h-20 px-3 py-3 flex flex-col items-center justify-center bg-primary border-b border-primary-dark text-on-primary">
                    <a href="{{ route('home') }}" class="flex flex-col items-center text-center group">
                        @if ($logoDrogueria)
                            {{-- Logo de la droguería (cualquier forma y color): sobre una placa blanca para que se lea en el azul. --}}
                            <span :class="sidebarCollapsed ? 'h-11 w-14 p-1' : 'h-16 w-[200px] p-1.5'"
                                  class="flex items-center justify-center rounded-xl bg-white shadow-md transition-all duration-300 group-hover:scale-105">
                                <img src="{{ $logoDrogueria }}" alt="{{ $cfg->nombre }}" class="max-h-full max-w-full object-contain">
                            </span>
                        @else
                            {{-- El logo es un círculo azul: el halo blanco lo despega del fondo azul del encabezado. --}}
                            <img src="{{ asset('img/logo-sides.png') }}" alt="SIDES"
                                 :class="sidebarCollapsed ? 'size-11' : 'size-14'"
                                 class="rounded-full shadow-[0_0_12px_2px_rgba(255,255,255,0.45)] transition-all duration-300 group-hover:scale-105">
                        @endif
                        <span x-show="!sidebarCollapsed" class="mt-1.5 max-w-[210px] truncate text-sm font-bold leading-tight">{{ $nombreCorto }}</span>
                        <span x-show="!sidebarCollapsed" class="text-xs font-semibold tracking-widest uppercase text-on-primary/75">SIDES V2</span>
                    </a>
                </div>

                <nav class="flex-1 overflow-y-auto py-3 px-2.5" aria-label="Menú principal">
                    @foreach ($seccionesMenu as $seccion => $itemsMenu)
                        <div class="space-y-1 {{ $loop->first ? '' : 'mt-4' }}">
                            @if ($seccion !== '')
                                <p x-show="!sidebarCollapsed" class="px-2.5 pb-1 text-[11px] font-bold uppercase tracking-widest text-sidebar-icon">{{ $seccion }}</p>
                                <div x-show="sidebarCollapsed" x-cloak class="mx-2.5 mb-2 border-t border-sidebar-border" aria-hidden="true"></div>
                            @endif
                            @foreach ($itemsMenu as $item)
                                @php $activo = request()->routeIs($item['ruta']); @endphp
                                <a href="{{ route($item['ruta']) }}" title="{{ $item['etiqueta'] }}"
                                   @if ($activo) aria-current="page" @endif
                                   class="group flex items-center gap-2.5 px-2.5 py-2 rounded-lg text-sm font-semibold transition-colors {{ $activo ? 'bg-primary text-on-primary shadow-sm' : 'text-sidebar-text hover:bg-primary hover:text-on-primary' }}">
                                    <span class="flex items-center justify-center size-9 rounded-md shrink-0 transition-colors {{ $activo ? 'bg-primary-soft text-primary-ink' : 'text-sidebar-icon group-hover:bg-primary-soft group-hover:text-primary-ink' }}">
                                        <svg class="size-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            {!! \App\Support\IconosSvg::path($item['icono']) !!}
                                        </svg>
                                    </span>
                                    <span x-show="!sidebarCollapsed" class="truncate">{{ $item['etiqueta'] }}</span>
                                </a>
                            @endforeach
                        </div>
                    @endforeach
                </nav>

                {{-- Solo el FT (viene de SEPED) y si SEPED_URL está configurado: pasa a SEPED sin contraseña. --}}
                @if (auth()->user()?->esFt() && \App\Http\Controllers\SsoController::urlSeped())
                    <a href="{{ route('sso.seped') }}" title="Ir a SEPED"
                       class="group shrink-0 mx-2.5 mt-2.5 flex items-center gap-2.5 px-2.5 py-2 rounded-lg text-sm font-semibold text-sidebar-text transition-colors hover:bg-primary hover:text-on-primary">
                        <span class="flex items-center justify-center size-9 rounded-md shrink-0 text-sidebar-icon group-hover:text-on-primary">
                            <svg class="size-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M15 3h6v6" /><path d="M10 14 21 3" /><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6" />
                            </svg>
                        </span>
                        <span x-show="!sidebarCollapsed">Ir a SEPED</span>
                    </a>
                @endif

                <form method="POST" action="{{ route('logout') }}" class="shrink-0 p-2.5 border-t border-sidebar-border">
                    @csrf
                    <button type="submit" title="Cerrar sesión"
                            class="group w-full flex items-center gap-2.5 px-2.5 py-2 rounded-lg text-sm font-semibold text-sidebar-text transition-colors hover:bg-rose-600 hover:text-white">
                        <span class="flex items-center justify-center size-9 rounded-md shrink-0 text-sidebar-icon group-hover:text-white">
                            <svg class="size-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="m16 17 5-5-5-5" /><path d="M21 12H9" /><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
                            </svg>
                        </span>
                        <span x-show="!sidebarCollapsed">Cerrar sesión</span>
                    </button>
                </form>
            </aside>

            <div class="flex-1 flex flex-col min-w-0">
                <header class="sticky top-0 z-30 bg-primary border-b border-primary-dark text-on-primary shadow-md">
                    <div class="flex items-center justify-between gap-3 px-4 sm:px-6 min-h-16 py-3">
                        <div class="flex items-center gap-2 min-w-0">
                            <button type="button" @click="sidebarOpen = true" aria-label="Abrir menú"
                                    class="lg:hidden p-2 -ms-2 rounded-xl text-on-primary/80 hover:bg-on-primary/10 hover:text-on-primary">
                                <svg class="size-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16" /></svg>
                            </button>
                            <button type="button" @click="sidebarCollapsed = !sidebarCollapsed"
                                    :aria-label="sidebarCollapsed ? 'Expandir menú lateral' : 'Encoger menú lateral'"
                                    class="hidden lg:flex p-2 rounded-xl text-on-primary/80 hover:bg-on-primary/15 hover:text-on-primary">
                                <svg class="size-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2" /><line x1="9" y1="3" x2="9" y2="21" /></svg>
                            </button>
                            @if ($titulo)
                                <h1 class="text-lg font-bold truncate">{{ $titulo }}</h1>
                            @endif
                        </div>

                        {{-- Recuadro del usuario: al tocarlo abre el menú con Cerrar sesión. --}}
                        <div x-data="{ abierto: false }" class="relative shrink-0" @click.outside="abierto = false" @keydown.escape="abierto = false">
                            <button type="button" @click="abierto = !abierto" aria-haspopup="menu" :aria-expanded="abierto" aria-label="Menú de usuario"
                                    class="flex items-center gap-x-2.5 py-1.5 ps-2 pe-2.5 rounded-2xl bg-on-primary/10 border border-on-primary/25 text-start transition-colors hover:bg-on-primary/20 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-on-primary/60">
                                <span class="flex items-center justify-center size-8 rounded-xl bg-white text-primary-ink font-extrabold text-xs">
                                    {{ strtoupper(mb_substr($usuario->name ?: 'U', 0, 1)) }}
                                </span>
                                <span class="hidden sm:flex flex-col leading-tight">
                                    <span class="text-xs font-bold">{{ $usuario->name }}</span>
                                    <span class="text-[11px] text-on-primary/80">{{ $usuario->email }}</span>
                                </span>
                                <svg class="size-4 text-on-primary/80 transition-transform" :class="abierto && 'rotate-180'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg>
                            </button>

                            <div x-show="abierto" x-cloak x-transition.origin.top.right role="menu"
                                 class="absolute end-0 z-40 mt-2 w-60 overflow-hidden rounded-xl bg-white py-1 text-slate-700 shadow-lg ring-1 ring-slate-200">
                                {{-- En el teléfono el recuadro solo muestra la inicial: el nombre y el correo van acá. --}}
                                <div class="border-b border-slate-100 px-3.5 py-2.5 leading-tight sm:hidden">
                                    <p class="truncate text-xs font-bold text-slate-800">{{ $usuario->name }}</p>
                                    <p class="truncate text-[11px] text-slate-500">{{ $usuario->email }}</p>
                                </div>
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button type="submit" role="menuitem"
                                            class="flex w-full items-center gap-2.5 px-3.5 py-2.5 text-start text-sm font-semibold text-rose-700 transition-colors hover:bg-rose-50">
                                        <svg class="size-4 shrink-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m16 17 5-5-5-5" /><path d="M21 12H9" /><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" /></svg>
                                        Cerrar sesión
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </header>

                <main class="flex-1 p-4 sm:p-6">
                    @if (session('mensaje'))
                        <div role="status" class="mx-auto mb-4 flex max-w-6xl items-start gap-2 rounded-xl bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800 ring-1 ring-emerald-200">
                            <svg class="mt-0.5 size-4 shrink-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5" /></svg>
                            {{ session('mensaje') }}
                        </div>
                    @endif
                    @if (session('error'))
                        <div role="alert" class="mx-auto mb-4 flex max-w-6xl items-start gap-2 rounded-xl bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800 ring-1 ring-rose-200">
                            <svg class="mt-0.5 size-4 shrink-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 9v4" /><path d="M12 17h.01" /><circle cx="12" cy="12" r="10" /></svg>
                            {{ session('error') }}
                        </div>
                    @endif

                    {{ $slot }}
                </main>

                <x-creditos class="border-t border-slate-200 px-4 py-3" />
            </div>
        </div>
    </body>
</html>
