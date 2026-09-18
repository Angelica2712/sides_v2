<x-layouts.app titulo="Monitor">
    {{--
        El monitor no se refresca por tiempo: escucha el canal privado sides-monitor.{codisb} y
        vuelve a pedir monitor/contenido apenas algo cambia, lo emita SIDES (un operario tomó o
        terminó un pedido) o seped_v2 (entró o se aprobó un pedido). La lógica está en
        resources/js/monitor.js; acá solo vive lo que no se reemplaza al actualizar.
    --}}
    <div class="space-y-4 fullscreen:overflow-y-auto fullscreen:bg-page fullscreen:p-6"
         x-data="monitorEnVivo({
             canal: @js('sides-monitor.'.$codisb),
             url: @js(route('monitor.contenido')),
             hora: @js($actualizado->format('H:i:s')),
         })">

        {{-- Barra de herramientas --}}
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-xl font-extrabold text-slate-900">Pedidos en proceso</h2>
                <p class="text-sm text-slate-500">
                    {{ $cfg?->nombre }} · actualizado a las <span x-text="hora" class="tabular-nums">{{ $actualizado->format('H:i:s') }}</span>
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <div role="group" aria-label="Tipo de vista" class="inline-flex rounded-xl bg-white p-1 shadow-sm ring-1 ring-slate-200">
                    <button type="button" @click="vista = 'tablero'" :aria-pressed="vista === 'tablero'"
                            :class="vista === 'tablero' ? 'bg-primary text-white shadow-sm' : 'text-slate-600 hover:text-slate-900'"
                            class="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-semibold transition-colors">
                        <svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="6" height="16" x="3" y="4" rx="1.5" /><rect width="6" height="10" x="10" y="4" rx="1.5" /><rect width="5" height="13" x="17" y="4" rx="1.5" /></svg>
                        Tablero
                    </button>
                    <button type="button" @click="vista = 'tabla'" :aria-pressed="vista === 'tabla'"
                            :class="vista === 'tabla' ? 'bg-primary text-white shadow-sm' : 'text-slate-600 hover:text-slate-900'"
                            class="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-semibold transition-colors">
                        <svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 5h18M3 12h18M3 19h18" /></svg>
                        Tabla
                    </button>
                </div>

                {{-- Estado de la conexión en vivo. Mismo chip que usa la cola de alcabala en seped_v2. --}}
                <div class="flex items-center gap-2 rounded-xl bg-white px-3 py-2 text-sm font-semibold shadow-sm ring-1"
                     :class="conectado ? 'text-emerald-700 ring-emerald-200' : 'text-amber-700 ring-amber-200'"
                     :title="conectado
                         ? 'Conectado: los cambios aparecen solos, sin esperar'
                         : (hubo
                             ? 'Se cortó la conexión en vivo: reintentando; mientras tanto se consulta cada minuto'
                             : 'Enlazando con el servidor de avisos…')"
                     aria-live="polite">
                    <span class="relative flex size-2" aria-hidden="true">
                        <span x-show="conectado" class="absolute inline-flex size-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
                        <span class="relative inline-flex size-2 rounded-full" :class="conectado ? 'bg-emerald-500' : 'bg-amber-500'"></span>
                    </span>
                    <span x-text="conectado
                        ? (actualizando ? 'Actualizando…' : 'En vivo')
                        : (hubo ? 'Reconectando…' : 'Conectando…')">En vivo</span>
                </div>

                <button type="button" @click="alternarPantalla()"
                        class="flex items-center gap-1.5 rounded-xl bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm ring-1 ring-slate-200 hover:bg-slate-50">
                    <svg class="size-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3" /></svg>
                    <span x-text="pantallaCompleta ? 'Salir de pantalla completa' : 'Pantalla completa'">Pantalla completa</span>
                </button>
            </div>
        </div>

        <div id="monitor-contenido" class="space-y-4" :class="actualizando && 'opacity-60 transition-opacity'">
            @include('monitor.contenido')
        </div>
    </div>
</x-layouts.app>
