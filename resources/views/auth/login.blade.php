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
    </head>
    <body class="font-sans antialiased bg-page text-slate-800">
        @php
            $funciones = ['Picking y packing con escáner', 'Batch picking por lotes', 'Guías, rutas y choferes', 'Monitor de pedidos en vivo'];
            $logoDrogueria = $cfg?->urlLogo();
        @endphp

        <main class="min-h-screen flex flex-col items-center justify-center gap-5 p-4 sm:p-8">
            <div class="w-full max-w-4xl overflow-hidden rounded-3xl bg-white shadow-xl ring-1 ring-slate-200 grid md:grid-cols-2">

                {{-- Publicidad: qué es SIDES. Se oculta en teléfono para dejar solo el formulario. --}}
                <section class="relative hidden md:flex flex-col justify-center overflow-hidden bg-gradient-to-br from-primary to-primary-dark p-10 text-white">
                    <div aria-hidden="true" class="pointer-events-none absolute -right-20 -top-20 size-64 rounded-full bg-white/10"></div>

                    <div class="relative">
                        <p class="text-xs font-bold uppercase tracking-widest text-white/70">Sistema de despacho</p>
                        <h1 class="mt-3 text-3xl font-extrabold leading-tight">
                            Del pedido al camión,<br>sin perder de vista nada.
                        </h1>
                        <p class="mt-3 max-w-xs text-sm text-white/80">
                            SIDES lleva los pedidos de SEPED por picking, packing, facturación y entrega.
                        </p>

                        <ul class="mt-8 space-y-3">
                            @foreach ($funciones as $f)
                                <li class="flex items-center gap-3 text-sm font-semibold">
                                    <svg class="size-5 shrink-0 text-white/70" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    {{ $f }}
                                </li>
                            @endforeach
                        </ul>
                    </div>
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
                            <p class="mt-2.5 text-3xl font-extrabold tracking-tight text-primary leading-none">SIDES</p>
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
                            <input type="checkbox" name="remember" class="rounded border-slate-300 text-primary focus:ring-primary">
                            Mantener la sesión iniciada
                        </label>

                        <button type="submit"
                                class="w-full rounded-xl bg-gradient-to-r from-primary to-primary-dark px-5 py-2.5 text-sm font-bold text-white shadow-md transition hover:brightness-110 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2">
                            Ingresar
                        </button>
                    </form>

                    @if ($logoDrogueria)
                        <p class="mt-6 flex items-center justify-center gap-2 text-xs font-semibold text-slate-400">
                            <img src="{{ asset('img/logo-sides.png') }}" alt="" class="size-6 rounded-full">
                            <span><span class="font-extrabold text-primary">SIDES</span> by FULLTECH360</span>
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
