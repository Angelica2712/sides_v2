{{--
    Camiones en el andén (resources/js/picking-vivo.js: camiones, elegirCamion, estadoCamion). Lo usan
    «Picking en vivo» y el Inicio. Solo sale con el módulo Guías (camiones !== null).
--}}
<section x-show="camiones !== null" x-cloak class="space-y-2" aria-label="Camiones en el andén">
    <h3 class="text-sm font-bold uppercase tracking-wide text-slate-500">Camiones en el andén</h3>
    <p x-show="camiones && camiones.length === 0" class="rounded-xl bg-slate-50 p-3 text-center text-sm text-slate-500">
        Ningún camión cargando. Aparecen al armar la guía y se van al salir a ruta.
    </p>
    <template x-for="c in camiones ?? []" :key="c.guia">
        <button type="button" @click="elegirCamion(c)" :aria-pressed="camionElegido === c.guia"
                class="block w-full rounded-xl bg-white p-3 text-left ring-1 transition hover:ring-slate-300"
                :class="camionElegido === c.guia ? 'ring-2 ring-orange-400' : 'ring-slate-200'">
            <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                    <p class="truncate text-sm font-extrabold text-slate-900">
                        <span x-text="c.placa"></span> <span class="font-semibold text-slate-500">· Guía #<span x-text="c.guia"></span></span>
                    </p>
                    <p class="truncate text-xs text-slate-500"><span x-text="c.chofer || 'Sin chofer'"></span><span x-show="c.ruta"> · <span x-text="c.ruta"></span></span></p>
                </div>
                <span class="shrink-0 rounded-lg px-2 py-0.5 text-xs font-bold ring-1" :class="estadoCamion(c).clase" x-text="estadoCamion(c).texto"></span>
            </div>
            <div class="mt-2 flex items-center gap-2">
                <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-200" role="progressbar" :aria-valuenow="c.cargados" aria-valuemin="0" :aria-valuemax="c.bultos">
                    <div class="h-full rounded-full transition-all duration-500" :style="{ width: (c.bultos ? Math.round(c.cargados / c.bultos * 100) : 0) + '%', background: estadoCamion(c).barra }"></div>
                </div>
                <span class="shrink-0 text-xs font-bold tabular-nums text-slate-600"><span x-text="c.cargados"></span>/<span x-text="c.bultos"></span> bultos</span>
            </div>
            <ul x-show="camionElegido === c.guia" class="mt-2 divide-y divide-slate-100 border-t border-slate-100">
                <template x-for="p in c.pedidos" :key="p.pedido">
                    <li class="flex items-center justify-between gap-2 py-1.5 text-xs">
                        <span class="min-w-0 truncate text-slate-700"><span class="font-bold tabular-nums">#<span x-text="p.pedido"></span></span> · <span x-text="p.cliente"></span></span>
                        <span x-show="p.cargados >= p.bultos" class="shrink-0 font-bold text-emerald-700">✓ Montado</span>
                        <span x-show="p.cargados < p.bultos" class="shrink-0 font-semibold tabular-nums text-amber-700"><span x-text="p.cargados"></span>/<span x-text="p.bultos"></span> bultos</span>
                    </li>
                </template>
            </ul>
            <p x-show="camionElegido !== c.guia" class="mt-1 text-xs text-slate-500">
                <span x-text="c.pedidos.filter((p) => p.cargados >= p.bultos).length"></span> de <span x-text="c.pedidos.length"></span> pedidos montados · toca para verlo
            </p>
        </button>
    </template>
    <p x-show="camionesFuera > 0" class="text-xs text-slate-500">
        <span x-text="camionesFuera"></span> camiones no caben en los puestos del almacén 3D: están en la lista pero no se dibujan. Agrega más puestos en Almacén 3D → Acomodo (+ Camión).
    </p>
</section>
