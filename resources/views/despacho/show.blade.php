@php
    $carga = $fase === 'carga';
    $bultos = $clientes->flatMap(fn ($c) => $c->pedidos->flatMap(fn ($p) => $p->bultos));
    $hechos = $carga ? $bultos->whereNotNull('feccargado')->count() : $bultos->whereNotNull('fecentregado')->count();
    // En carga se ocultan los clientes ya cargados; en descarga, los ya entregados.
    $pendientes = $clientes->reject(fn ($c) => $carga ? $c->cargado : $c->terminado)->values();
    $listos = $clientes->count() - $pendientes->count();
@endphp

<x-layouts.app :titulo="($carga ? 'Carga' : 'Entrega').' guía #'.$guia->id">
    <div class="mx-auto max-w-4xl space-y-4" x-data="despachoGuia(@js(session('resultado')))">
        <a href="{{ route('despacho.index') }}" class="text-sm font-semibold text-primary hover:underline">← Volver a mis guías</a>

        <section class="space-y-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h2 class="text-xl font-black text-slate-900">{{ $carga ? 'Cargar' : 'Entregar' }} · Guía #{{ $guia->id }}</h2>
                    <p class="text-sm text-slate-500">{{ $guia->ruta }} · {{ $guia->nomchofer }}</p>
                </div>
                <p class="text-2xl font-black tabular-nums text-slate-900">{{ $hechos }} / {{ $bultos->count() }}</p>
            </div>
            <p class="text-sm text-slate-600">
                @if ($carga)
                    Escanea cada bulto al subirlo al camión. Los clientes van en orden inverso a la entrega: lo último que se entrega entra primero.
                @else
                    Escanea cada bulto al entregarlo, o entrega todos los de un cliente con su botón.
                @endif
            </p>

            <form x-ref="formulario" method="POST" action="{{ route($carga ? 'despacho.cargar' : 'despacho.entregar', $guia->id) }}" class="flex gap-2">
                @csrf
                <label for="lectura" class="sr-only">Código del bulto</label>
                <input x-ref="lectura" x-model="lectura" id="lectura" name="etiqueta" type="text" :inputmode="teclado ? 'text' : 'none'" autocomplete="off"
                       placeholder="Escanea el código del bulto" @keydown.enter.prevent="leer()"
                       class="block w-full rounded-xl border-slate-300 px-4 py-3 font-mono text-lg shadow-sm focus:border-primary focus:ring-primary">
                <button type="submit" class="rounded-xl bg-slate-800 px-4 py-3 text-sm font-bold text-white hover:bg-slate-900">{{ $carga ? 'Cargar' : 'Entregar' }}</button>
            </form>
            <x-lector-opciones />
        </section>

        @if ($pendientes->isEmpty())
            <section class="rounded-2xl bg-emerald-50 p-8 text-center ring-1 ring-emerald-200">
                <h3 class="text-lg font-bold text-emerald-900">{{ $carga ? 'Todos los bultos están cargados' : 'No quedan entregas pendientes' }}</h3>
                @if ($carga && auth()->user()->activarGuiaDescarga)
                    <a href="{{ route('despacho.show', [$guia->id, 'descarga']) }}" class="mt-3 inline-block rounded-xl bg-emerald-600 px-5 py-3 text-sm font-bold text-white hover:bg-emerald-700">Empezar la entrega</a>
                @endif
            </section>
        @endif

        @foreach ($pendientes as $cliente)
            @php
                $bultosCliente = $cliente->pedidos->flatMap(fn ($p) => $p->bultos);
                $faltan = $carga ? $bultosCliente->whereNull('feccargado')->count() : $bultosCliente->whereNull('fecentregado')->count();
            @endphp
            <section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
                <header class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-slate-50 px-4 py-3">
                    <div>
                        <h3 class="font-bold text-slate-900">{{ $cliente->nomcli }}</h3>
                        <p class="text-xs text-slate-500">{{ $cliente->codcli }} · faltan {{ $faltan }} de {{ $bultosCliente->count() }} bultos</p>
                    </div>
                    @unless ($carga)
                        <form method="POST" action="{{ route('despacho.entregar', $guia->id) }}"
                              onsubmit="return confirm(@js('¿Entregar los '.$faltan.' bultos de '.$cliente->nomcli.'?'))">
                            @csrf
                            <input type="hidden" name="codcli" value="{{ $cliente->codcli }}">
                            <button type="submit" class="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-bold text-white hover:bg-emerald-700">Entregar todo</button>
                        </form>
                    @endunless
                </header>
                <ul class="grid gap-2 p-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($bultosCliente as $bulto)
                        @php $listo = $carga ? $bulto->feccargado !== null : $bulto->fecentregado !== null; @endphp
                        <li class="flex items-center justify-between gap-2 rounded-xl px-3 py-2 ring-1 {{ $listo ? 'bg-emerald-50 ring-emerald-200' : 'ring-slate-200' }}">
                            <div>
                                <p class="font-mono font-bold text-slate-900">{{ $bulto->etiqueta }}</p>
                                <p class="text-xs text-slate-500">Pedido #{{ $bulto->numepedi }}</p>
                            </div>
                            @if ($listo)
                                <span class="text-xs font-bold text-emerald-700">{{ $carga ? 'Cargado' : 'Entregado' }}</span>
                            @else
                                <form method="POST" action="{{ route($carga ? 'despacho.cargar' : 'despacho.entregar', $guia->id) }}">
                                    @csrf
                                    <input type="hidden" name="etiqueta" value="{{ $bulto->etiqueta }}">
                                    <button type="submit" class="rounded-lg bg-white px-3 py-1.5 text-xs font-bold text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">{{ $carga ? 'Cargar' : 'Entregar' }}</button>
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach

        @if ($listos > 0)
            <p class="text-center text-sm text-slate-500">{{ $listos }} {{ $listos === 1 ? 'cliente ya' : 'clientes ya' }} {{ $carga ? ($listos === 1 ? 'está cargado' : 'están cargados') : ($listos === 1 ? 'recibió todo' : 'recibieron todo') }}.</p>
        @endif
    </div>
</x-layouts.app>
