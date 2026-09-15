@php
    $fecha = fn ($valor) => $valor ? \Illuminate\Support\Carbon::parse($valor)->format('d-m-y H:i') : '—';
    $formato = \App\Support\FormatosEtiqueta::de($cfg?->formatoPersEtiq);
@endphp

<x-layouts.app titulo="Etiquetas">
    <div class="mx-auto max-w-3xl space-y-4">
        <div>
            <h2 class="text-xl font-extrabold text-slate-900">Etiquetas de bultos</h2>
            <p class="text-sm text-slate-500">Busca un pedido con packing terminado e imprime una etiqueta por bulto. También puedes escanear una etiqueta ya impresa para reimprimirla.</p>
        </div>

        <form method="GET" action="{{ route('etiquetas.index') }}" class="flex gap-2" role="search">
            <label for="buscar" class="sr-only">Número de pedido, etiqueta o recipiente</label>
            <input id="buscar" name="buscar" type="search" value="{{ $buscar }}" autocomplete="off" @unless ($pedido) autofocus @endunless
                   placeholder="Pedido, etiqueta o recipiente"
                   class="w-full rounded-2xl border-slate-300 bg-white px-4 py-3.5 text-lg font-semibold shadow-sm focus:border-primary focus:ring-primary">
            <button type="submit" class="rounded-2xl bg-slate-800 px-5 text-sm font-bold text-white hover:bg-slate-900">Buscar</button>
        </form>

        @if ($error)
            <div role="alert" class="rounded-xl bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800 ring-1 ring-rose-200">{{ $error }}</div>
        @endif

        @if ($pedido)
            <section class="space-y-5 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-2xl font-black text-slate-900">Pedido #{{ $pedido->id }}</p>
                        <p class="truncate font-semibold text-slate-700">{{ $pedido->nomcli }}</p>
                        <p class="text-sm text-slate-500">{{ $pedido->codcli }} · {{ $pedido->ruta ?: 'Sin ruta' }}</p>
                    </div>
                    <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700 ring-1 ring-emerald-200">{{ $pedido->estado }}</span>
                </div>

                <dl class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                    <div><dt class="text-xs font-semibold text-slate-500">Recipiente</dt><dd class="font-bold text-slate-900">{{ $pedido->recipiente ?: '—' }}</dd></div>
                    <div><dt class="text-xs font-semibold text-slate-500">Procesado</dt><dd class="font-bold text-slate-900">{{ $fecha($pedido->fecprocesado) }}</dd></div>
                    <div><dt class="text-xs font-semibold text-slate-500">Despachador</dt><dd class="truncate font-bold text-slate-900">{{ $pedido->despachador ?: '—' }}</dd></div>
                    <div><dt class="text-xs font-semibold text-slate-500">Embalador</dt><dd class="truncate font-bold text-slate-900">{{ $pedido->embalador ?: '—' }}</dd></div>
                    @if ($pedido->entrega)
                        <div class="col-span-2 sm:col-span-4"><dt class="text-xs font-semibold text-slate-500">Dirección de entrega</dt><dd class="text-slate-800">{{ $pedido->entrega }}</dd></div>
                    @endif
                </dl>

                <form method="POST" action="{{ route('etiquetas.generar', $pedido->id) }}" class="flex flex-wrap items-end gap-3 border-t border-slate-200 pt-4">
                    @csrf
                    <div>
                        <label for="bultos" class="block text-sm font-semibold text-slate-700">Bultos</label>
                        <input id="bultos" name="bultos" type="number" min="1" max="{{ \App\Services\Etiquetas\EtiquetasService::MAX_BULTOS }}" required inputmode="numeric" autofocus
                               value="{{ old('bultos', max(1, (int) $pedido->cantBultos)) }}"
                               class="mt-1.5 block w-28 rounded-xl border-slate-300 text-center text-2xl font-black tabular-nums shadow-sm focus:border-primary focus:ring-primary">
                    </div>
                    <button type="submit" class="rounded-xl bg-primary px-5 py-3 font-bold text-white hover:bg-primary-dark">Imprimir etiquetas</button>
                    @if ($cfg?->activarImpTicket)
                        <a href="{{ route('etiquetas.ticket', $pedido->id) }}" class="rounded-xl bg-white px-5 py-3 font-semibold text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">Imprimir ticket</a>
                    @endif
                </form>
                @error('bultos')
                    <p role="alert" class="text-sm font-semibold text-rose-700">{{ $message }}</p>
                @enderror

                <p class="text-xs text-slate-500">
                    Formato de {{ $formato['nombre'] }}. Cada bulto lleva el código {{ $pedido->id }}-01, {{ $pedido->id }}-02… que se escanea en las guías.
                </p>
            </section>
        @endif
    </div>
</x-layouts.app>
