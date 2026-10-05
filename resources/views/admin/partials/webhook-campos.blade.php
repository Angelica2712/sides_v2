{{-- Campos compartidos por el alta y la edición de un webhook (admin/webhooks.blade.php). --}}
@php
    $eventosElegidos = $webhook?->eventos ?? [];
    $inputClase = 'mt-1.5 block w-full rounded-xl border-slate-300 px-3.5 py-2.5 shadow-sm focus:border-primary focus:ring-primary';
@endphp

<div class="grid gap-4 sm:grid-cols-2">
    <div>
        <label for="{{ $prefijo }}-nombre" class="block text-sm font-semibold text-slate-700">Nombre</label>
        <input id="{{ $prefijo }}-nombre" name="nombre" type="text" maxlength="100" required value="{{ $webhook?->nombre }}" placeholder="App de despacho" class="{{ $inputClase }}">
    </div>
    <div>
        <label for="{{ $prefijo }}-token" class="block text-sm font-semibold text-slate-700">Token Bearer <span class="font-normal text-slate-400">(opcional)</span></label>
        <input id="{{ $prefijo }}-token" name="token" type="password" maxlength="500" autocomplete="off"
               placeholder="{{ $webhook?->token ? 'Sin cambios' : 'Si la aplicación lo pide' }}" class="{{ $inputClase }}">
    </div>
    <div class="sm:col-span-2">
        <label for="{{ $prefijo }}-url" class="block text-sm font-semibold text-slate-700">URL que recibe los avisos</label>
        <input id="{{ $prefijo }}-url" name="url" type="url" maxlength="500" required value="{{ $webhook?->url }}" placeholder="https://app.ejemplo.com/webhooks/sides" class="{{ $inputClase }} font-mono text-sm">
    </div>
</div>

<fieldset>
    <legend class="text-sm font-semibold text-slate-700">Eventos</legend>
    <p class="text-xs text-slate-500">Si no marcas ninguno, recibe todos.</p>
    <div class="mt-2 grid gap-1.5 sm:grid-cols-2">
        @foreach (\App\Services\Webhooks\EventosWebhook::CATALOGO as $evento => $descripcion)
            <label class="flex cursor-pointer items-start gap-2 rounded-lg p-2 text-sm ring-1 ring-slate-200 has-checked:bg-primary-soft has-checked:ring-primary">
                <input type="checkbox" name="eventos[]" value="{{ $evento }}" @checked(in_array($evento, $eventosElegidos, true))
                       class="mt-0.5 size-4 rounded border-slate-300 text-primary-ink focus:ring-primary">
                <span>
                    <span class="block font-mono text-xs font-bold text-slate-800">{{ $evento }}</span>
                    <span class="block text-xs text-slate-500">{{ $descripcion }}</span>
                </span>
            </label>
        @endforeach
    </div>
</fieldset>
