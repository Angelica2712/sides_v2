@props(['titulo' => null])

@php
    $usuario = auth()->user();
    $cfg = $usuario->cfg;
    $itemsMenu = array_merge(
        [['etiqueta' => 'Inicio', 'ruta' => 'home', 'icono' => 'home']],
        \App\Support\MenuSides::visibles($usuario, $cfg)
    );
    $nombreCorto = $cfg?->nomcorto ?: ($cfg?->nombre ?: 'SIDES');
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $titulo ? $titulo.' · ' : '' }}SIDES {{ $nombreCorto }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
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

                <div class="shrink-0 min-h-20 px-3 py-3 flex flex-col items-center justify-center bg-primary border-b border-primary-dark text-white">
                    <a href="{{ route('home') }}" class="flex flex-col items-center text-center group">
                        <span :class="sidebarCollapsed ? 'size-11 text-lg' : 'size-14 text-2xl'"
                              class="flex items-center justify-center rounded-xl bg-white font-extrabold text-primary shadow-md transition-all duration-300 group-hover:scale-105">
                            {{ strtoupper(mb_substr($nombreCorto, 0, 1)) }}
                        </span>
                        <span x-show="!sidebarCollapsed" class="mt-1.5 max-w-[210px] truncate text-sm font-bold leading-tight">{{ $nombreCorto }}</span>
                        <span x-show="!sidebarCollapsed" class="text-xs font-semibold tracking-widest uppercase text-white/75">SIDES V2</span>
                    </a>
                </div>

                <nav class="flex-1 overflow-y-auto py-3 px-2.5 space-y-1" aria-label="Menú principal">
                    @foreach ($itemsMenu as $item)
                        @php $activo = request()->routeIs($item['ruta']); @endphp
                        <a href="{{ route($item['ruta']) }}" title="{{ $item['etiqueta'] }}"
                           @if ($activo) aria-current="page" @endif
                           class="group flex items-center gap-2.5 px-2.5 py-2 rounded-lg text-sm font-semibold transition-colors {{ $activo ? 'bg-primary text-white shadow-sm' : 'text-slate-300 hover:bg-primary hover:text-white' }}">
                            <span class="flex items-center justify-center size-9 rounded-md shrink-0 transition-colors {{ $activo ? 'bg-primary-soft text-primary' : 'text-slate-400 group-hover:bg-white group-hover:text-primary' }}">
                                <svg class="size-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    {!! \App\Support\IconosSvg::path($item['icono']) !!}
                                </svg>
                            </span>
                            <span x-show="!sidebarCollapsed" class="truncate">{{ $item['etiqueta'] }}</span>
                        </a>
                    @endforeach
                </nav>

                <form method="POST" action="{{ route('logout') }}" class="shrink-0 p-2.5 border-t border-sidebar-border">
                    @csrf
                    <button type="submit" title="Cerrar sesión"
                            class="group w-full flex items-center gap-2.5 px-2.5 py-2 rounded-lg text-sm font-semibold text-slate-300 transition-colors hover:bg-rose-600 hover:text-white">
                        <span class="flex items-center justify-center size-9 rounded-md shrink-0 text-slate-400 group-hover:text-white">
                            <svg class="size-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="m16 17 5-5-5-5" /><path d="M21 12H9" /><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
                            </svg>
                        </span>
                        <span x-show="!sidebarCollapsed">Cerrar sesión</span>
                    </button>
                </form>
            </aside>

            <div class="flex-1 flex flex-col min-w-0">
                <header class="sticky top-0 z-30 bg-primary border-b border-primary-dark text-white shadow-md">
                    <div class="flex items-center justify-between gap-3 px-4 sm:px-6 min-h-16 py-3">
                        <div class="flex items-center gap-2 min-w-0">
                            <button type="button" @click="sidebarOpen = true" aria-label="Abrir menú"
                                    class="lg:hidden p-2 -ms-2 rounded-xl text-white/80 hover:bg-white/10 hover:text-white">
                                <svg class="size-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16" /></svg>
                            </button>
                            <button type="button" @click="sidebarCollapsed = !sidebarCollapsed"
                                    :aria-label="sidebarCollapsed ? 'Expandir menú lateral' : 'Encoger menú lateral'"
                                    class="hidden lg:flex p-2 rounded-xl text-white/80 hover:bg-white/15 hover:text-white">
                                <svg class="size-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2" /><line x1="9" y1="3" x2="9" y2="21" /></svg>
                            </button>
                            @if ($titulo)
                                <h1 class="text-lg font-bold truncate">{{ $titulo }}</h1>
                            @endif
                        </div>

                        <div class="flex items-center gap-x-2.5 py-1.5 ps-2 pe-3.5 rounded-2xl bg-white/10 border border-white/25">
                            <span class="flex items-center justify-center size-8 rounded-xl bg-white text-primary font-extrabold text-xs">
                                {{ strtoupper(mb_substr($usuario->name ?: 'U', 0, 1)) }}
                            </span>
                            <span class="hidden sm:flex flex-col leading-tight">
                                <span class="text-xs font-bold">{{ $usuario->name }}</span>
                                <span class="text-[11px] text-white/80">{{ $usuario->email }}</span>
                            </span>
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
            </div>
        </div>
    </body>
</html>
