<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>Iniciar sesión · SIDES</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased bg-page text-slate-800">
        <main class="min-h-screen grid lg:grid-cols-2">
            <section class="hidden lg:flex flex-col justify-between bg-primary text-white p-12">
                <div class="flex items-center gap-3">
                    <span class="flex items-center justify-center size-12 rounded-xl bg-white text-primary text-xl font-extrabold shadow-md">S</span>
                    <span class="text-sm font-semibold tracking-widest uppercase text-white/80">SIDES V2</span>
                </div>

                <div>
                    <h1 class="text-4xl font-extrabold leading-tight">Despacho de pedidos</h1>
                    <p class="mt-3 max-w-md text-lg text-white/85">
                        Picking, packing, guías y rutas en un solo lugar, conectado con SEPED.
                    </p>
                </div>

                <p class="text-sm font-semibold text-white/75">{{ $cfg?->nombre ?? 'Sistema de despacho' }}</p>
            </section>

            <section class="flex items-center justify-center p-6 sm:p-10">
                <div class="w-full max-w-sm">
                    <div class="lg:hidden mb-8 flex items-center gap-3">
                        <span class="flex items-center justify-center size-11 rounded-xl bg-primary text-white text-lg font-extrabold shadow-md">S</span>
                        <span class="text-sm font-semibold tracking-widest uppercase text-slate-500">SIDES V2</span>
                    </div>

                    <h2 class="text-2xl font-extrabold text-slate-900">Iniciar sesión</h2>
                    <p class="mt-1 text-sm text-slate-500">
                        Entra con tu usuario de {{ $cfg ? ($cfg->nomcorto ?: $cfg->nombre) : 'SIDES' }}.
                    </p>

                    <form method="POST" action="{{ route('login') }}" class="mt-8 space-y-5" novalidate>
                        @csrf

                        <div>
                            <label for="email" class="block text-sm font-semibold text-slate-700">Correo</label>
                            <input id="email" name="email" type="email" value="{{ old('email') }}"
                                   required autofocus autocomplete="username"
                                   @error('email') aria-invalid="true" aria-describedby="email-error" @enderror
                                   class="mt-1.5 block w-full rounded-xl border-slate-300 bg-white px-3.5 py-2.5 text-sm shadow-sm focus:border-primary focus:ring-primary">
                            @error('email')
                                <p id="email-error" class="mt-1.5 text-sm text-rose-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="password" class="block text-sm font-semibold text-slate-700">Contraseña</label>
                            <input id="password" name="password" type="password"
                                   required autocomplete="current-password"
                                   @error('password') aria-invalid="true" aria-describedby="password-error" @enderror
                                   class="mt-1.5 block w-full rounded-xl border-slate-300 bg-white px-3.5 py-2.5 text-sm shadow-sm focus:border-primary focus:ring-primary">
                            @error('password')
                                <p id="password-error" class="mt-1.5 text-sm text-rose-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <label class="flex items-center gap-2 text-sm text-slate-600">
                            <input type="checkbox" name="remember" class="rounded border-slate-300 text-primary focus:ring-primary">
                            Mantener la sesión iniciada
                        </label>

                        <button type="submit"
                                class="w-full rounded-xl bg-primary px-5 py-2.5 text-sm font-bold text-white shadow-sm transition-colors hover:bg-primary-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2">
                            Entrar
                        </button>
                    </form>
                </div>
            </section>
        </main>
    </body>
</html>
