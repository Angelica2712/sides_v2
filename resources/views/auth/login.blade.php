<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <link rel="icon" type="image/png" href="{{ asset('img/favicon-sides.png') }}">

        <title>Iniciar sesión · SIDES</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        {{-- Con la droguería conocida (su enlace, la instalación o el equipo), sus colores de SEPED. --}}
        @if ($temaDrogueria = \App\Support\TemaSides::estilo($cfg?->codisb))
            <style>{!! $temaDrogueria !!}</style>
        @endif
    </head>
    <body class="font-sans antialiased bg-page text-slate-800">
        @php
            $funciones = ['Picking y packing con escáner', 'Batch picking por lotes', 'Guías, rutas y choferes', 'Monitor de pedidos en vivo'];
            $logoDrogueria = $cfg?->urlLogo();
        @endphp

        <main class="min-h-screen flex flex-col items-center justify-center gap-5 p-4 sm:p-8">
            <div class="w-full max-w-4xl overflow-hidden rounded-3xl bg-white shadow-xl ring-1 ring-slate-200 grid md:grid-cols-2">

                {{-- Publicidad: qué es SIDES. Se oculta en teléfono para dejar solo el formulario. --}}
                {{-- Tres bloques repartidos a lo alto: el mensaje arriba, la ilustración al centro y lo que trae abajo. --}}
                <section class="relative hidden md:flex flex-col justify-between gap-8 overflow-hidden bg-gradient-to-br from-primary to-primary-dark p-10 text-on-primary">
                    <div aria-hidden="true" class="pointer-events-none absolute -right-20 -top-20 size-64 rounded-full bg-on-primary/10"></div>

                    <div class="relative">
                        <p class="inline-block rounded-full bg-on-primary/15 px-3 py-1 text-[11px] font-bold uppercase tracking-widest">Sistema de despacho</p>
                        <h1 class="mt-4 text-balance text-[1.7rem] font-extrabold leading-[1.15] lg:text-3xl">
                            Del pedido al camión, sin perder de vista nada.
                        </h1>
                        <p class="mt-3 text-pretty text-[15px] leading-relaxed text-on-primary/85">
                            SIDES lleva los pedidos de SEPED por picking, packing, facturación y entrega.
                        </p>
                    </div>

                    <div class="relative">
                        {{-- Ilustración del recorrido: pedido revisado, caja escaneada y camión. Dibujada con
                             currentColor para que tome el color de letra de la paleta de la droguería. --}}
                        <svg class="mx-auto w-full max-w-sm" viewBox="0 0 360 150" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            {{-- Piso y recorrido --}}
                            <path d="M8 132h344" stroke-opacity=".35" />
                            <path d="M84 84h34M222 84h20" stroke-opacity=".55" stroke-dasharray="2 8" />
                            <path d="m112 78 7 6-7 6M236 78l7 6-7 6" stroke-opacity=".55" />

                            {{-- Pedido con sus renglones revisados --}}
                            <rect x="14" y="36" width="62" height="84" rx="8" fill="currentColor" fill-opacity=".14" />
                            <path d="M34 36v-5a4 4 0 0 1 4-4h14a4 4 0 0 1 4 4v5" fill="currentColor" fill-opacity=".3" />
                            <path d="m24 60 4 4 7-8M24 80l4 4 7-8M24 100l4 4 7-8" />
                            <path d="M44 60h22M44 80h22M44 100h14" stroke-opacity=".6" />

                            {{-- Caja con su código de barras y la línea del lector --}}
                            {{-- Caja de cartón vista de frente: tapa plana con su cinta y, abajo, el código de barras. --}}
                            <rect x="128" y="50" width="84" height="70" rx="6" fill="currentColor" fill-opacity=".14" />
                            <path d="M128 68h84" />
                            <path d="M162 50v18h16V50" fill="currentColor" fill-opacity=".3" />
                            <path d="M145 80v28M150 80v28M157 80v28M161 80v28M168 80v28M175 80v28M179 80v28M186 80v28M190 80v28M195 80v28" stroke-width="2.4" stroke-linecap="butt" stroke-opacity=".7" />
                            {{-- Línea del lector: del mismo color que el resto, sobresale de la caja para que se distinga de las barras. --}}
                            <path d="M120 94h100" stroke-width="3.5" />
                            <circle cx="212" cy="50" r="13" fill="currentColor" stroke="none" />
                            <path d="m206 50 4 4 8-9" class="stroke-primary" stroke-width="3" />

                            {{-- Camión --}}
                            <path d="M252 58h56a4 4 0 0 1 4 4v54h-64V62a4 4 0 0 1 4-4Z" fill="currentColor" fill-opacity=".14" />
                            <path d="M312 78h18a6 6 0 0 1 5 3l9 16a6 6 0 0 1 1 3v16h-33V78Z" fill="currentColor" fill-opacity=".28" />
                            <path d="M322 86h9l6 11h-15z" stroke-width="2" stroke-opacity=".8" />
                            <path d="M262 72h36M262 84h24" stroke-opacity=".6" />
                            <circle cx="270" cy="120" r="10" class="fill-primary-dark" />
                            <circle cx="326" cy="120" r="10" class="fill-primary-dark" />
                            <circle cx="270" cy="120" r="3" fill="currentColor" stroke="none" />
                            <circle cx="326" cy="120" r="3" fill="currentColor" stroke="none" />
                        </svg>
                    </div>

                    <ul class="relative grid grid-cols-2 gap-x-5 gap-y-3 border-t border-on-primary/20 pt-6">
                        @foreach ($funciones as $f)
                            <li class="flex items-start gap-2 text-[13px] font-semibold leading-snug">
                                <svg class="mt-px size-4 shrink-0 text-on-primary/75" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <span class="text-balance">{{ $f }}</span>
                            </li>
                        @endforeach
                    </ul>
                </section>

                {{-- Formulario --}}
                <section class="flex flex-col justify-center px-6 py-8 sm:px-10">
                    <div class="flex flex-col items-center text-center">
                        @if ($logoDrogueria)
                            {{-- Droguería conocida (su enlace o este equipo): su logo manda; el de SIDES queda abajo, pequeño. --}}
                            <img src="{{ $logoDrogueria }}" alt="{{ $cfg->nombre }}" class="h-28 w-auto max-w-72 object-contain">
                            <p class="mt-3 text-xl font-extrabold leading-tight text-slate-900">{{ $cfg->nomcorto ?: $cfg->nombre }}</p>
                        @else
                            <img src="{{ asset('img/logo-sides.png') }}" alt="" class="size-20 rounded-full shadow-lg ring-4 ring-primary/15">
                            <p class="mt-2.5 text-3xl font-extrabold tracking-tight text-primary-ink leading-none">SIDES</p>
                            <p class="mt-1.5 text-xs font-bold uppercase tracking-[0.25em] text-slate-400">by FULLTECH360</p>
                        @endif
                        <p class="mt-4 text-sm text-slate-500">
                            Entra con tu usuario de {{ $cfg ? ($cfg->nomcorto ?: $cfg->nombre) : 'SIDES' }}.
                        </p>
                    </div>

                    <form method="POST" action="{{ route('login') }}" class="mt-5 space-y-4" novalidate>
                        @csrf

                        <div>
                            <label for="email" class="block text-sm font-semibold text-slate-700">Correo electrónico</label>
                            <input id="email" name="email" type="email" value="{{ old('email') }}"
                                   required autofocus autocomplete="username"
                                   @error('email') aria-invalid="true" aria-describedby="email-error" @enderror
                                   class="mt-1.5 block w-full rounded-xl border-slate-300 bg-slate-50 px-3.5 py-2.5 text-sm shadow-sm focus:border-primary focus:bg-white focus:ring-primary">
                            @error('email')
                                <p id="email-error" class="mt-1.5 text-sm text-rose-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="password" class="block text-sm font-semibold text-slate-700">Contraseña</label>
                            <input id="password" name="password" type="password"
                                   required autocomplete="current-password"
                                   @error('password') aria-invalid="true" aria-describedby="password-error" @enderror
                                   class="mt-1.5 block w-full rounded-xl border-slate-300 bg-slate-50 px-3.5 py-2.5 text-sm shadow-sm focus:border-primary focus:bg-white focus:ring-primary">
                            @error('password')
                                <p id="password-error" class="mt-1.5 text-sm text-rose-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <label class="flex items-center gap-2 text-sm text-slate-600">
                            <input type="checkbox" name="remember" class="rounded border-slate-300 text-primary-ink focus:ring-primary">
                            Mantener la sesión iniciada
                        </label>

                        <button type="submit"
                                class="w-full rounded-xl bg-gradient-to-r from-primary to-primary-dark px-5 py-2.5 text-sm font-bold text-on-primary shadow-md transition hover:brightness-110 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2">
                            Ingresar
                        </button>
                    </form>

                    @if ($logoDrogueria)
                        <p class="mt-6 flex items-center justify-center gap-2 text-xs font-semibold text-slate-400">
                            <img src="{{ asset('img/logo-sides.png') }}" alt="" class="size-6 rounded-full">
                            <span><span class="font-extrabold text-primary-ink">SIDES</span> by FULLTECH360</span>
                        </p>
                    @elseif ($cfg?->nombre)
                        <p class="mt-6 text-center text-xs font-semibold text-slate-400">{{ $cfg->nombre }}</p>
                    @endif
                </section>
            </div>

            <x-creditos />
        </main>
    </body>
</html>
