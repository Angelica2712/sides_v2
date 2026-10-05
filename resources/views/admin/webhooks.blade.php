@php
    $secretoNuevo = session('secreto');
    $colorEstado = [
        'ENTREGADO' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'PENDIENTE' => 'bg-amber-50 text-amber-800 ring-amber-200',
        'FALLIDO' => 'bg-rose-50 text-rose-700 ring-rose-200',
    ];
@endphp

<x-layouts.app titulo="Webhooks">
    <div class="mx-auto max-w-4xl space-y-4">
        <a href="{{ route('admin.index') }}" class="text-sm font-semibold text-primary-ink hover:underline">← Volver a droguerías</a>

        <div>
            <h2 class="text-xl font-extrabold text-slate-900">Webhooks de {{ $drogueria->nombre ?: $drogueria->codisb }}</h2>
            <p class="text-sm text-slate-500">
                Aplicaciones externas que reciben un aviso (POST JSON firmado) cada vez que un pedido de esta droguería cambia en el despacho.
                Si la aplicación no responde, SIDES reintenta a los 1, 5, 15, 60 y 360 minutos.
            </p>
        </div>

        @if ($errors->any())
            <div role="alert" class="rounded-xl bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800 ring-1 ring-rose-200">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        @if ($secretoNuevo)
            <div role="status" class="space-y-2 rounded-2xl bg-amber-50 p-4 text-sm ring-1 ring-amber-300">
                <p class="font-bold text-amber-900">Copia el secreto ahora: no se vuelve a mostrar.</p>
                <p class="text-amber-800">La aplicación lo usa para comprobar la cabecera <code>X-Sides-Firma</code> de cada aviso.</p>
                <input type="text" readonly value="{{ $secretoNuevo['valor'] }}" onclick="this.select()" aria-label="Secreto del webhook"
                       class="block w-full rounded-xl border-amber-300 bg-white px-3.5 py-2.5 font-mono text-sm">
            </div>
        @endif

        @forelse ($webhooks as $webhook)
            <section class="space-y-3 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200" x-data="{ editar: false }">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="flex items-center gap-2 text-lg font-black text-slate-900">
                            {{ $webhook->nombre }}
                            <span class="rounded-full px-2.5 py-0.5 text-xs font-bold ring-1 {{ $webhook->activo ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-slate-100 text-slate-500 ring-slate-200' }}">
                                {{ $webhook->activo ? 'Activo' : 'Pausado' }}
                            </span>
                        </p>
                        <p class="break-all font-mono text-xs text-slate-500">{{ $webhook->url }}</p>
                        <p class="mt-1 text-xs text-slate-500">
                            {{ empty($webhook->eventos) ? 'Todos los eventos' : count($webhook->eventos).' eventos' }}
                            · {{ $webhook->token ? 'Con token Bearer' : 'Sin token' }}
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <form method="POST" action="{{ route('admin.webhooks.probar', $webhook) }}">
                            @csrf
                            <button type="submit" class="rounded-xl bg-primary px-3 py-2 text-sm font-bold text-on-primary hover:bg-primary-dark">Probar</button>
                        </form>
                        <button type="button" @click="editar = ! editar" class="rounded-xl bg-white px-3 py-2 text-sm font-semibold text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">Editar</button>
                    </div>
                </div>

                <div x-show="editar" x-cloak class="space-y-4 border-t border-slate-200 pt-4">
                    <form method="POST" action="{{ route('admin.webhooks.update', $webhook) }}" class="space-y-4">
                        @csrf @method('PUT')
                        @include('admin.partials.webhook-campos', ['webhook' => $webhook, 'prefijo' => "w{$webhook->id}"])
                        <label class="flex items-center gap-2 text-sm font-semibold text-slate-700">
                            <input type="checkbox" name="activo" value="1" @checked($webhook->activo) class="size-4 rounded border-slate-300 text-primary-ink focus:ring-primary">
                            Enviar avisos (desmarcado queda en pausa)
                        </label>
                        @if ($webhook->token)
                            <label class="flex items-center gap-2 text-sm text-slate-600">
                                <input type="checkbox" name="quitar_token" value="1" class="size-4 rounded border-slate-300 text-primary-ink focus:ring-primary">
                                Quitar el token actual
                            </label>
                        @endif
                        <div class="flex justify-end">
                            <button type="submit" class="rounded-xl bg-primary px-4 py-2 text-sm font-bold text-on-primary hover:bg-primary-dark">Guardar</button>
                        </div>
                    </form>

                    <div class="flex flex-wrap justify-between gap-2 border-t border-slate-200 pt-3">
                        <form method="POST" action="{{ route('admin.webhooks.secreto', $webhook) }}" onsubmit="return confirm('La aplicación dejará de aceptar avisos hasta que le cargues el secreto nuevo. ¿Continuar?')">
                            @csrf
                            <button type="submit" class="rounded-xl px-3 py-2 text-sm font-semibold text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">Generar secreto nuevo</button>
                        </form>
                        <form method="POST" action="{{ route('admin.webhooks.destroy', $webhook) }}" onsubmit="return confirm('¿Eliminar este webhook y su historial de avisos?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="rounded-xl px-3 py-2 text-sm font-semibold text-rose-700 ring-1 ring-rose-200 hover:bg-rose-50">Eliminar</button>
                        </form>
                    </div>
                </div>
            </section>
        @empty
            <p class="rounded-2xl bg-white p-5 text-sm text-slate-500 shadow-sm ring-1 ring-slate-200">Esta droguería todavía no avisa a ninguna aplicación.</p>
        @endforelse

        <details class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200" @if ($webhooks->isEmpty() || $errors->any()) open @endif>
            <summary class="cursor-pointer p-5 text-lg font-extrabold text-slate-900">Nuevo webhook</summary>
            <form method="POST" action="{{ route('admin.webhooks.store', $drogueria->codisb) }}" class="space-y-4 px-5 pb-5">
                @csrf
                @include('admin.partials.webhook-campos', ['webhook' => null, 'prefijo' => 'nuevo'])
                <div class="flex justify-end">
                    <button type="submit" class="rounded-xl bg-primary px-5 py-2.5 text-sm font-bold text-on-primary hover:bg-primary-dark">Crear webhook</button>
                </div>
            </form>
        </details>

        @if ($entregas->isNotEmpty())
            <section class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
                <h3 class="p-5 pb-3 text-lg font-extrabold text-slate-900">Últimos avisos</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-50 text-xs font-bold uppercase text-slate-500">
                            <tr>
                                <th class="px-5 py-2">Fecha</th>
                                <th class="px-3 py-2">Aplicación</th>
                                <th class="px-3 py-2">Evento</th>
                                <th class="px-3 py-2">Estado</th>
                                <th class="px-3 py-2">Detalle</th>
                                <th class="px-5 py-2"><span class="sr-only">Acciones</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($entregas as $entrega)
                                <tr>
                                    <td class="whitespace-nowrap px-5 py-2 text-slate-600">{{ $entrega->created_at?->format('d/m H:i:s') }}</td>
                                    <td class="px-3 py-2">{{ $entrega->webhook?->nombre }}</td>
                                    <td class="px-3 py-2 font-mono text-xs">
                                        {{ $entrega->evento }}
                                        @if (isset($entrega->payload['pedido']['id'])) · #{{ $entrega->payload['pedido']['id'] }} @endif
                                        @if (isset($entrega->payload['lote']['id'])) · lote {{ $entrega->payload['lote']['id'] }} @endif
                                    </td>
                                    <td class="px-3 py-2">
                                        <span class="rounded-full px-2 py-0.5 text-xs font-bold ring-1 {{ $colorEstado[$entrega->estado] ?? '' }}">{{ $entrega->estado }}</span>
                                        <span class="text-xs text-slate-400">{{ $entrega->intentos }}×</span>
                                    </td>
                                    <td class="max-w-xs truncate px-3 py-2 text-xs text-slate-500" title="{{ $entrega->ultimo_error }}">
                                        {{ $entrega->ultimo_error ?: ($entrega->ultimo_codigo ? "HTTP {$entrega->ultimo_codigo}" : '') }}
                                        @if ($entrega->estado === 'PENDIENTE' && $entrega->proximo_intento_at)
                                            · reintento {{ $entrega->proximo_intento_at->format('H:i') }}
                                        @endif
                                    </td>
                                    <td class="px-5 py-2 text-right">
                                        @if ($entrega->estado === 'FALLIDO')
                                            <form method="POST" action="{{ route('admin.webhooks.reintentar', $entrega) }}">
                                                @csrf
                                                <button type="submit" class="text-xs font-bold text-primary-ink hover:underline">Reintentar</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    </div>
</x-layouts.app>
