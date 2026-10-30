/**
 * Acomodo del almacén (resources/views/almacen/acomodo.blade.php, desde Administración o desde
 * Almacén 3D > Ajustes avanzados): el formulario, una vista 3D y el plano visto desde arriba.
 *
 * El plano se arma aquí mismo (armarPlano) con lo que sale de los productos y la configuración que
 * se está editando, así que todo cambio se ve al momento. En el 3D y en el plano se eligen anaqueles
 * (uno o su fila) para arrastrarlos, girarlos, borrarlos o cambiarles el número, se crean
 * pasillos y se ponen entradas: config.pasillos, disposicion, quitados, extras y entradas. Un
 * anaquel con productos ubicados (plano.ocupados) no se puede borrar ni renumerar.
 *
 * El visor vive fuera del estado de Alpine: los objetos de three.js no soportan los proxies reactivos.
 */
import { crearVisor, planoEjemplo } from './almacen-3d';
import { FONDO, anaquelesDe, armarPlano, claveAnaquel, disponer, enPlano, limitesDe } from './almacen-plano';
import { describir, formatear, interpretar, nombreZona, usarFormato } from './ubicacion';

const dosDigitos = (numero) => String(numero).padStart(2, '0');
const redondear = (metros) => Math.round(metros * 10) / 10;
const COLORES_ZONA = ['#2563eb', '#16a34a', '#9333ea', '#db2777', '#0891b2', '#ca8a04', '#dc2626'];
const MAXIMO_POR_LADO = 49;

/** Teléfono: pantalla angosta. Coincide con el md: de Tailwind que usa la vista. */
const esTelefono = () => window.matchMedia('(max-width: 767px)').matches;
// Cuánto puede temblar un dedo (en píxeles) sin que deje de ser un toque.
const TEMBLOR_DEDO = 12;
// Al guardar desde la pantalla completa la página se recarga: se vuelve a ella con la misma cámara.
const CLAVE_FOCO = 'sides-acomodo-foco';

function leerFoco() {
    try {
        const guardado = sessionStorage.getItem(CLAVE_FOCO);
        sessionStorage.removeItem(CLAVE_FOCO);
        return guardado ? JSON.parse(guardado) : null;
    } catch {
        return null;
    }
}

export default function almacenConfig({ plano, config, guardado = null, conErrores = false }) {
    let visor = null;
    let espera = null;
    let turnoDibujo = 0;
    const foco = leerFoco();
    let vistaInicial = foco?.vista ?? null;
    // Dedos apoyados en el plano (pointerId => [x, y]), para el zoom con dos dedos en el teléfono.
    const dedos = new Map();
    const distanciaDedos = () => {
        const [[x1, y1], [x2, y2]] = [...dedos.values()];
        return Math.hypot(x2 - x1, y2 - y1);
    };
    // Lo que sale solo de las ubicaciones de los productos; el resto lo arma armarPlano.
    const productos = plano.productos ?? plano.zonas;
    const ocupados = new Set(plano.ocupados ?? []);
    // PHP manda [] cuando no hay ninguno; un arreglo perdería las claves al pasarlo a JSON.
    const comoObjeto = (valor) => (Array.isArray(valor) || !valor ? {} : valor);
    config.pasillos = comoObjeto(config.pasillos);
    config.disposicion = comoObjeto(config.disposicion);
    config.quitados = Array.isArray(config.quitados) ? config.quitados : [];
    config.extras = Array.isArray(config.extras) ? config.extras : [];
    config.entradas = Array.isArray(config.entradas) ? config.entradas : [];
    // Desde Administración se edita el almacén de otra droguería, con su propio formato.
    usarFormato(config.formato);

    const inicial = armarPlano(productos, config);
    const muestra = inicial.zonas.length ? inicial : planoEjemplo();
    const primera = muestra.zonas.find((zona) => zona.pasillos.length);
    const pasillo = primera?.pasillos[0];
    const anaquel = pasillo?.anaqueles.find((a) => a.anaquel === 3) ?? pasillo?.anaqueles[0];

    return {
        config,
        prueba: primera ? formatear({ zona: primera.zona, pasillo: pasillo.pasillo, anaquel: anaquel.anaquel, estante: 'A', posicion: 1 }) : '',
        mensaje: '',
        // Por qué no se pudo hacer lo pedido con lo elegido.
        avisoPlano: '',
        vista: '3d',

        // Anaquel elegido (o, en su lugar, la entrada elegida, por su índice en config.entradas),
        // si se trabaja con todo su pasillo, arrastre en curso en el plano y lo que se ve del plano.
        seleccionado: null,
        entrada: null,
        moverPasillo: false,
        arrastre: null,
        caja: [0, 0, 10, 10],
        // Formularios del pasillo nuevo y del cambio de número (null = cerrados).
        nuevo: null,
        renumero: null,
        // Teléfono: el visor ocupa toda la pantalla, con lo necesario para asignar debajo.
        enfocado: Boolean(foco) && esTelefono() && !conErrores,
        // Lo último que se asignó, para proponer el número que sigue.
        ultimo: null,

        /** Plano con lo que se está editando; sin pasillos, el almacén de ejemplo. */
        get armado() {
            return armarPlano(productos, this.config);
        },
        get ejemplo() {
            return !this.armado.zonas.length;
        },
        get base() {
            const armado = this.armado;
            return armado.zonas.length ? armado : planoEjemplo();
        },
        get planoPrevio() {
            return { ...this.base, config: JSON.parse(JSON.stringify(this.config)) };
        },
        get detalle() {
            return describir(interpretar(this.prueba), this.planoPrevio);
        },
        get disposicion() {
            return disponer(this.base, this.config);
        },
        get borrados() {
            return this.config.quitados.length;
        },
        get entradaElegida() {
            return this.entrada === null ? null : (this.config.entradas[this.entrada] ?? null);
        },
        /** Entradas como se ven en el plano, con la que se está arrastrando corrida. */
        get entradasPlano() {
            const { tipo, indice, dx = 0, dz = 0 } = this.arrastre ?? {};
            return this.config.entradas.map((e, i) => (tipo === 'entrada' && indice === i ? { ...e, x: e.x + dx, z: e.z + dz } : e));
        },
        /** El anaquel elegido tiene productos ubicados: no se puede borrar. */
        get elegidoOcupado() {
            return Boolean(this.elegido && ocupados.has(this.elegido.clave));
        },
        get anaqueles() {
            const { claves = [], dx = 0, dz = 0 } = this.arrastre ?? {};
            return this.disposicion.anaqueles.map((a) => (claves.includes(a.clave) ? { ...a, x: a.x + dx, z: a.z + dz } : a));
        },
        get elegido() {
            return this.disposicion.anaqueles.find((a) => a.clave === this.seleccionado) ?? null;
        },
        get movidos() {
            return Object.keys(this.config.disposicion).length;
        },
        get viewBox() {
            return this.caja.join(' ');
        },
        /** Pasillos registrados para la lista del formulario, ordenados. */
        get filasPasillos() {
            return Object.keys(this.config.pasillos)
                .sort()
                .map((clave) => ({ clave, zona: clave.slice(0, 2), pasillo: Number(clave.slice(3)) }));
        },

        init() {
            this.$watch('config', () => {
                clearTimeout(espera);
                espera = setTimeout(() => this.redibujar(), 250);
            });
            this.$watch('seleccionado', () => this.marcar3d());
            this.$watch('moverPasillo', () => this.marcar3d());
            this.$watch('enfocado', (enfocado) => document.documentElement.classList.toggle('overflow-hidden', enfocado));
            if (this.enfocado) {
                document.documentElement.classList.add('overflow-hidden');
                this.avisoPlano = guardado ?? '';
            }
            this.encajar();
            this.dibujar();
            this.$el.addEventListener('alpine:destroy', () => visor?.destruir());
        },

        /** Dibuja el 3D; al redibujar por un cambio, la cámara se queda donde estaba. */
        async dibujar() {
            const turno = ++turnoDibujo;
            const vista = visor?.vista() ?? vistaInicial;
            vistaInicial = null;
            let nuevo;
            try {
                nuevo = await crearVisor(this.$refs.visor, this.planoPrevio, { vista });
            } catch (error) {
                this.mensaje = 'No se pudo dibujar el almacén en este equipo.';
                console.error(error);
                return;
            }
            // Mientras se armaba cambió otra vez: vale el dibujo más nuevo.
            if (turno !== turnoDibujo) {
                nuevo.destruir();
                return;
            }
            // El anterior se quita recién ahora, para que la pantalla no parpadee.
            visor?.destruir();
            visor = nuevo;
            visor.editar({ alElegir: (clave) => this.elegir3d(clave), alMover: (dx, dz) => this.mover3d(dx, dz) });
            this.marcar3d();
            this.resaltar();
        },

        /** Sin cortar lo que el dedo esté haciendo en el visor. */
        redibujar() {
            if (visor?.ocupado()) {
                espera = setTimeout(() => this.redibujar(), 250);
                return;
            }
            this.dibujar();
        },

        /** Guardando desde la pantalla completa, se vuelve a ella al recargar. */
        recordarFoco() {
            if (!this.enfocado) {
                return;
            }
            try {
                sessionStorage.setItem(CLAVE_FOCO, JSON.stringify({ vista: visor?.vista() ?? null }));
            } catch {
                // Sin almacenamiento solo se vuelve a la página normal.
            }
        },

        navegar3d(accion) {
            visor?.navegar(accion);
        },

        /** Ilumina la ubicación de prueba en el 3D sin mover la cámara. */
        resaltar() {
            const ubicacion = interpretar(this.prueba);
            this.mensaje = '';
            if (!visor || !ubicacion) {
                return;
            }
            if (!visor.resaltar(ubicacion, [], { volar: false })) {
                this.mensaje = `La ubicación ${ubicacion.codigo} no está en el plano.`;
            }
        },

        // ---- Elegir y mover en el 3D ----

        marcar3d() {
            visor?.marcar(this.elegido ? this.grupoDe(this.elegido).map((a) => a.clave) : []);
        },

        elegir3d(clave) {
            this.entrada = null;
            this.avisoPlano = '';
            this.seleccionado = clave;
            this.abrirRenumerar();
        },

        mover3d(dx, dz) {
            const a = this.elegido;
            if (!a) {
                return;
            }
            for (const b of this.grupoDe(a)) {
                this.fijar(b, b.x + dx, b.z + dz, b.giro);
            }
        },

        // ---- Plano visto desde arriba ----

        nombreZona(zona) {
            return nombreZona(zona, this.config);
        },
        colorZona(zona) {
            const indice = this.base.zonas.findIndex((z) => z.zona === zona);
            return indice < 0 ? '#475569' : COLORES_ZONA[indice % COLORES_ZONA.length];
        },

        /** Contenido del plano (zonas sin pasillos y anaqueles). Alpine no repite elementos dentro de un SVG. */
        get svgPlano() {
            const texto = (valor) => String(valor).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);
            const zonas = this.disposicion.zonasSolas.map((z) => `
                <rect x="${z.x - z.ancho / 2}" y="${z.z - z.fondo / 2}" width="${z.ancho}" height="${z.fondo}"
                      fill="#e2e8f0" stroke="#94a3b8" stroke-width="0.05" stroke-dasharray="0.2 0.15"></rect>
                <text x="${z.x}" y="${z.z}" font-size="0.4" text-anchor="middle" dominant-baseline="middle" fill="#475569"
                      font-weight="700">${texto(`${z.zona} · ${this.nombreZona(z.zona)}`)}</text>`);
            const elegidos = new Set(this.elegido ? this.grupoDe(this.elegido).map((a) => a.clave) : []);
            const anaqueles = this.anaqueles.map((a) => {
                const elegido = elegidos.has(a.clave);
                // SVG gira en sentido horario con y hacia abajo: el giro del plano va con signo contrario.
                return `
                <g data-clave="${a.clave}" style="cursor: grab">
                    <g transform="translate(${a.x} ${a.z}) rotate(${-a.giro})">
                        <rect x="${-a.ancho / 2}" y="-0.3" width="${a.ancho}" height="0.6" rx="0.05"
                              fill="${elegido ? '#fed7aa' : a.movido ? '#fef3c7' : '#ffffff'}"
                              stroke="${elegido ? '#ea580c' : this.colorZona(a.zona)}" stroke-width="${elegido ? 0.08 : 0.04}"></rect>
                        <line x1="${-a.ancho / 2}" x2="${a.ancho / 2}" y1="0.3" y2="0.3" stroke="#f97316" stroke-width="0.1" stroke-linecap="round"></line>
                    </g>
                    <text x="${a.x}" y="${a.z}" font-size="0.26" text-anchor="middle" dominant-baseline="middle"
                          fill="#0f172a" font-weight="700" pointer-events="none">${this.etiquetaAnaquel(a)}</text>
                </g>`;
            });
            // Puertas: el vano verde, la hoja abriendo hacia adentro y el nombre afuera.
            const entradas = this.entradasPlano.map((e, i) => {
                const elegida = i === this.entrada;
                // El nombre va del lado de afuera de la puerta (-z de la puerta, girado como ella).
                const t = (e.giro * Math.PI) / 180;
                const [nx, nz] = [e.x - 0.75 * Math.sin(t), e.z - 0.45 * Math.cos(t)];
                return `
                <g data-entrada="${i}" style="cursor: grab">
                    <g transform="translate(${e.x} ${e.z}) rotate(${-e.giro})">
                        <rect x="-0.8" y="-0.7" width="1.6" height="1.4" fill="transparent"></rect>
                        <path d="M -0.6 0 A 1.2 1.2 0 0 0 0.6 1.2" fill="none" stroke="#16a34a" stroke-width="0.03" stroke-dasharray="0.08 0.06"></path>
                        <line x1="0.6" y1="0" x2="0.6" y2="1.2" stroke="#16a34a" stroke-width="0.05"></line>
                        <rect x="-0.6" y="-0.09" width="1.2" height="0.18" rx="0.04"
                              fill="${elegida ? '#bbf7d0' : '#dcfce7'}" stroke="${elegida ? '#ea580c' : '#16a34a'}" stroke-width="${elegida ? 0.08 : 0.05}"></rect>
                    </g>
                    <text x="${nx}" y="${nz}" font-size="0.28" text-anchor="middle" dominant-baseline="middle"
                          fill="#14532d" font-weight="800" pointer-events="none">${texto(e.nombre || 'Entrada')}</text>
                </g>`;
            });
            return [...zonas, ...anaqueles, ...entradas].join('');
        },

        /** El plano sigue recibiendo el puntero aunque salga del SVG mientras se arrastra. */
        capturar(evento) {
            try {
                this.$refs.plano.setPointerCapture(evento.pointerId);
            } catch {
                // Un puntero que ya se levantó no se puede capturar; el arrastre sigue igual.
            }
        },

        /** Sobre un anaquel lo toma para moverlo; sobre el fondo desplaza la vista. */
        presionar(evento) {
            dedos.set(evento.pointerId, [evento.clientX, evento.clientY]);
            if (dedos.size === 2) {
                // Segundo dedo en el teléfono: se deja lo que hacía el primero y se pellizca para el zoom.
                this.capturar(evento);
                this.arrastre = { tipo: 'pellizco', distancia: distanciaDedos(), caja: [...this.caja] };
                return;
            }
            const puerta = evento.target.closest('[data-entrada]')?.dataset.entrada;
            if (puerta !== undefined) {
                return this.tomarEntrada(evento, Number(puerta));
            }
            const clave = evento.target.closest('[data-clave]')?.dataset.clave;
            const a = clave && this.disposicion.anaqueles.find((b) => b.clave === clave);
            return a ? this.tomar(evento, a) : this.tomarFondo(evento);
        },

        /** Vuelve a encuadrar el plano completo. */
        encajar() {
            const { anaqueles, zonasSolas } = this.disposicion;
            const limites = limitesDe(anaqueles, 2) ?? [0, 0, 10, 10];
            for (const z of zonasSolas) {
                limites[0] = Math.min(limites[0], z.x - z.ancho / 2 - 2);
                limites[1] = Math.min(limites[1], z.z - z.fondo / 2 - 2);
                limites[2] = Math.max(limites[2], z.x + z.ancho / 2 + 2);
                limites[3] = Math.max(limites[3], z.z + z.fondo / 2 + 2);
            }
            for (const e of this.config.entradas) {
                limites[0] = Math.min(limites[0], e.x - 2);
                limites[1] = Math.min(limites[1], e.z - 2);
                limites[2] = Math.max(limites[2], e.x + 2);
                limites[3] = Math.max(limites[3], e.z + 2);
            }
            this.caja = [limites[0], limites[1], limites[2] - limites[0], limites[3] - limites[1]];
        },

        acercar(factor) {
            const [x, y, ancho, alto] = this.caja;
            const nuevoAncho = Math.min(Math.max(ancho * factor, 3), 400);
            const nuevoAlto = (alto * nuevoAncho) / ancho;
            this.caja = [x + (ancho - nuevoAncho) / 2, y + (alto - nuevoAlto) / 2, nuevoAncho, nuevoAlto];
        },

        puntoEnPlano(evento) {
            const svg = this.$refs.plano;
            const punto = svg.createSVGPoint();
            punto.x = evento.clientX;
            punto.y = evento.clientY;
            const { x, y } = punto.matrixTransform(svg.getScreenCTM().inverse());
            return [x, y];
        },

        /** Anaqueles que se mueven, giran, borran o renumeran juntos: el elegido, o su fila. */
        grupoDe(a) {
            return this.moverPasillo ? this.filaDe(a) : [a];
        },

        /**
         * La fila del anaquel tocado: los de su pasillo alineados con él (mismo eje de giro y a menos
         * de un fondo de distancia), no la fila de enfrente aunque tenga el mismo número de pasillo.
         */
        filaDe(a) {
            const acostado = (b) => b.giro % 180 === 0;
            return this.disposicion.anaqueles.filter((b) => b.zona === a.zona && b.pasillo === a.pasillo
                && acostado(b) === acostado(a)
                && Math.abs(acostado(a) ? b.z - a.z : b.x - a.x) < FONDO);
        },

        /** El anaquel y todo su pasillo (las dos filas). */
        pasilloDe(a) {
            return this.disposicion.anaqueles.filter((b) => b.zona === a.zona && b.pasillo === a.pasillo);
        },

        tomarEntrada(evento, indice) {
            this.entrada = indice;
            this.seleccionado = null;
            this.capturar(evento);
            this.arrastre = { tipo: 'entrada', indice, inicio: this.puntoEnPlano(evento), dx: 0, dz: 0, ...this.inicioDedo(evento) };
        },

        /** Pone una puerta nueva en el centro de lo que se está viendo y la deja elegida. */
        agregarEntrada() {
            if (this.config.entradas.length >= 20) {
                return;
            }
            const [x, y, ancho, alto] = this.caja;
            this.config.entradas.push({ x: redondear(x + ancho / 2), z: redondear(y + alto / 2), giro: 0, nombre: '' });
            this.entrada = this.config.entradas.length - 1;
            this.seleccionado = null;
        },

        tomar(evento, a) {
            this.entrada = null;
            this.avisoPlano = '';
            this.seleccionado = a.clave;
            this.abrirRenumerar();
            this.prueba = formatear({ zona: a.zona, pasillo: a.pasillo, anaquel: a.anaquel, estante: 'A', posicion: 1 });
            this.resaltar();
            this.capturar(evento);
            this.arrastre = { tipo: 'anaquel', claves: this.grupoDe(a).map((b) => b.clave), inicio: this.puntoEnPlano(evento), dx: 0, dz: 0, ...this.inicioDedo(evento) };
        },

        /** Con el dedo, dónde se apoyó: lo que mueve un anaquel es un arrastre, no el temblor de un toque. */
        inicioDedo(evento) {
            return evento.pointerType === 'touch' ? { dedo: [evento.clientX, evento.clientY] } : {};
        },

        soltarElegido() {
            this.seleccionado = null;
            this.renumero = null;
            this.entrada = null;
        },

        /** Arrastrar el fondo desplaza la vista; con el dedo, solo un toque sin arrastre suelta lo elegido. */
        tomarFondo(evento) {
            if (evento.pointerType !== 'touch') {
                this.soltarElegido();
            }
            this.capturar(evento);
            this.arrastre = { tipo: 'vista', inicio: [evento.clientX, evento.clientY], caja: [...this.caja], ...this.inicioDedo(evento) };
        },

        mover(evento) {
            if (dedos.has(evento.pointerId)) {
                dedos.set(evento.pointerId, [evento.clientX, evento.clientY]);
            }
            if (!this.arrastre) {
                return;
            }
            if (this.arrastre.tipo === 'pellizco') {
                // Juntar los dedos aleja y separarlos acerca, alrededor del centro de la vista.
                const [x, y, ancho, alto] = this.arrastre.caja;
                const factor = Math.min(Math.max(this.arrastre.distancia / Math.max(distanciaDedos(), 1), 3 / ancho), 400 / ancho);
                this.caja = [x + (ancho - ancho * factor) / 2, y + (alto - alto * factor) / 2, ancho * factor, alto * factor];
                return;
            }
            if (this.arrastre.dedo) {
                if (Math.hypot(evento.clientX - this.arrastre.dedo[0], evento.clientY - this.arrastre.dedo[1]) <= TEMBLOR_DEDO) {
                    return;
                }
                // Desde aquí es un arrastre; se cuenta desde donde está el dedo, sin salto.
                this.arrastre.dedo = null;
                if (this.arrastre.tipo === 'vista') {
                    this.arrastre.inicio = [evento.clientX, evento.clientY];
                } else {
                    this.arrastre.inicio = this.puntoEnPlano(evento);
                }
            }
            if (this.arrastre.tipo === 'vista') {
                const svg = this.$refs.plano.getBoundingClientRect();
                const [x, y, ancho, alto] = this.arrastre.caja;
                const escala = Math.max(ancho / svg.width, alto / svg.height);
                this.caja = [
                    x - (evento.clientX - this.arrastre.inicio[0]) * escala,
                    y - (evento.clientY - this.arrastre.inicio[1]) * escala,
                    ancho,
                    alto,
                ];
                return;
            }
            const [x, z] = this.puntoEnPlano(evento);
            this.arrastre.dx = redondear(x - this.arrastre.inicio[0]);
            this.arrastre.dz = redondear(z - this.arrastre.inicio[1]);
        },

        soltar(evento) {
            dedos.delete(evento?.pointerId);
            const arrastre = this.arrastre;
            this.arrastre = null;
            if (arrastre?.tipo === 'vista' && arrastre.dedo) {
                this.soltarElegido();
            }
            if (arrastre?.tipo === 'anaquel' && this.renumero && !esTelefono()) {
                // El cuadro de números va debajo del plano: se trae a la vista ya soltado el anaquel.
                this.$nextTick(() => this.$refs.renumero?.scrollIntoView({ behavior: 'smooth', block: 'nearest' }));
            }
            if (!['anaquel', 'entrada'].includes(arrastre?.tipo) || (!arrastre.dx && !arrastre.dz)) {
                return;
            }
            if (arrastre.tipo === 'entrada') {
                const e = this.config.entradas[arrastre.indice];
                e.x = redondear(e.x + arrastre.dx);
                e.z = redondear(e.z + arrastre.dz);
                return;
            }
            for (const a of this.disposicion.anaqueles.filter((b) => arrastre.claves.includes(b.clave))) {
                this.fijar(a, a.x + arrastre.dx, a.z + arrastre.dz, a.giro);
            }
        },

        fijar(a, x, z, giro) {
            this.config.disposicion[a.clave] = { x: Math.round(x * 100) / 100, z: Math.round(z * 100) / 100, giro: ((giro % 360) + 360) % 360 };
        },

        /** Gira 90° el anaquel elegido, o su fila alrededor de su centro. */
        girar() {
            if (this.entradaElegida) {
                this.entradaElegida.giro = (this.entradaElegida.giro + 90) % 360;
                return;
            }
            const a = this.elegido;
            if (!a) {
                return;
            }
            const grupo = this.grupoDe(a);
            const [x1, z1, x2, z2] = limitesDe(grupo);
            const [cx, cz] = grupo.length > 1 ? [(x1 + x2) / 2, (z1 + z2) / 2] : [a.x, a.z];
            for (const b of grupo) {
                // Mismo sentido que el giro de los anaqueles (ver enPlano).
                const [x, z] = enPlano({ x: cx, z: cz, giro: 90 }, b.x - cx, b.z - cz);
                this.fijar(b, x, z, b.giro + 90);
            }
        },

        /** Devuelve el anaquel elegido (o su fila) a su lugar automático. */
        restaurar() {
            const a = this.elegido;
            if (!a) {
                return;
            }
            for (const b of this.grupoDe(a)) {
                delete this.config.disposicion[b.clave];
            }
        },

        /** Saca anaqueles del plano: los sueltos se olvidan, los de un pasillo registrado se marcan quitados. */
        sacar(claves) {
            const sueltos = new Set(this.config.extras);
            const deUnPasillo = claves.filter((clave) => !sueltos.has(clave));
            this.config.extras = this.config.extras.filter((clave) => !claves.includes(clave));
            this.config.quitados = [...new Set([...this.config.quitados, ...deUnPasillo])].sort();
            for (const clave of claves) {
                delete this.config.disposicion[clave];
            }
        },

        /**
         * Borra el anaquel elegido o, con «Toda la fila», su fila. Si la fila es el pasillo entero, el pasillo registrado sin
         * productos se quita también de la lista de pasillos; los anaqueles con productos ubicados
         * se quedan y se avisa por qué.
         */
        borrar() {
            this.avisoPlano = '';
            if (this.entradaElegida) {
                if (confirm(`¿Quitar la entrada «${this.entradaElegida.nombre || 'Entrada'}»?`)) {
                    this.config.entradas.splice(this.entrada, 1);
                    this.entrada = null;
                }
                return;
            }
            const a = this.elegido;
            if (!a) {
                return;
            }
            if (this.ejemplo) {
                this.avisoPlano = 'Este es un almacén de ejemplo: para borrar o cambiar números, primero tócale «Usar este ejemplo como mi almacén».';
                return;
            }
            const grupo = this.grupoDe(a).map((b) => b.clave);
            const claves = grupo.filter((clave) => !ocupados.has(clave));
            const clavePasillo = `${a.zona}-${dosDigitos(a.pasillo)}`;
            const registrado = clavePasillo in this.config.pasillos;
            if (!claves.length) {
                this.avisoPlano = registrado
                    ? 'Tiene productos ubicados: muévelos a otro hueco (en «Ver almacén») o cámbiales la ubicación antes de borrarlo.'
                    : `El pasillo ${dosDigitos(a.pasillo)} sale de las ubicaciones que tienen los productos. Para quitarlo hay que cambiarles la ubicación a esos productos.`;
                return;
            }
            const pasilloEntero = this.moverPasillo && claves.length === this.pasilloDe(a).length && registrado;
            const que = pasilloEntero
                ? `el pasillo ${dosDigitos(a.pasillo)} completo`
                : claves.length === 1 ? `el anaquel ${this.etiquetaAnaquel(a)}` : `${claves.length} anaqueles de la fila del pasillo ${dosDigitos(a.pasillo)}`;
            if (!confirm(`¿Borrar ${que}? Se quita del plano al guardar el acomodo.`)) {
                return;
            }
            this.sacar(claves);
            if (pasilloEntero) {
                delete this.config.pasillos[clavePasillo];
            } else if (claves.length < grupo.length) {
                this.avisoPlano = `${grupo.length - claves.length} anaqueles tienen productos ubicados y se quedaron.`;
            }
            this.seleccionado = null;
        },

        devolverBorrados() {
            if (confirm('¿Volver a poner los anaqueles borrados?')) {
                this.config.quitados = [];
                this.$nextTick(() => this.encajar());
            }
        },

        restaurarTodo() {
            if (confirm('¿Volver todos los anaqueles a su lugar automático?')) {
                this.config.disposicion = {};
                this.seleccionado = null;
                this.$nextTick(() => this.encajar());
            }
        },

        // ---- Pasillos: crear, renombrar y cambiar números ----

        /** Hay un pasillo de verdad con esa zona y número (los del almacén de ejemplo no cuentan). */
        existePasillo(zona, numero) {
            return `${zona}-${dosDigitos(numero)}` in this.config.pasillos
                || this.armado.zonas.some((z) => z.zona === zona && z.pasillos.some((p) => p.pasillo === numero));
        },

        abrirNuevoPasillo() {
            const zona = this.elegido?.zona ?? this.filasPasillos.at(-1)?.zona ?? 'PK';
            let numero = 1;
            while (numero < 99 && this.existePasillo(zona, numero)) {
                numero++;
            }
            const anterior = this.config.pasillos[this.filasPasillos.at(-1)?.clave];
            this.nuevo = {
                zona,
                pasillo: numero,
                izquierda: anterior?.izquierda ?? 5,
                derecha: anterior?.derecha ?? 5,
                estantes: anterior?.estantes ?? this.config.estantes,
                posiciones: anterior?.posiciones ?? this.config.posiciones,
            };
            this.renumero = null;
            this.avisoPlano = '';
        },

        /** Crea el pasillo del formulario y lo deja elegido entero, listo para arrastrarlo a su lugar. */
        crearPasillo() {
            const zona = String(this.nuevo.zona).trim().toUpperCase();
            const numero = Number(this.nuevo.pasillo);
            const lado = (valor) => Math.min(Math.max(Number(valor) || 0, 0), MAXIMO_POR_LADO);
            const [izquierda, derecha] = [lado(this.nuevo.izquierda), lado(this.nuevo.derecha)];
            if (!/^[A-Z]{2}$/.test(zona) || !(numero >= 1 && numero <= 99)) {
                this.avisoPlano = 'La zona son 2 letras (por ejemplo PK) y el pasillo un número del 1 al 99.';
                return;
            }
            if (izquierda + derecha < 1) {
                this.avisoPlano = 'El pasillo debe tener al menos un anaquel a algún lado.';
                return;
            }
            if (this.existePasillo(zona, numero)) {
                this.avisoPlano = `Ya hay un pasillo ${dosDigitos(numero)} en ${zona}.`;
                return;
            }
            const clave = `${zona}-${dosDigitos(numero)}`;
            this.config.pasillos[clave] = {
                izquierda,
                derecha,
                estantes: Math.min(Math.max(Number(this.nuevo.estantes) || 4, 1), 12),
                posiciones: Math.min(Math.max(Number(this.nuevo.posiciones) || 4, 1), 30),
            };
            this.nuevo = null;
            this.avisoPlano = '';
            this.entrada = null;
            this.moverPasillo = true;
            this.seleccionado = claveAnaquel(zona, numero, anaquelesDe(this.config.pasillos[clave])[0]);
        },

        /** Cambia la clave de un pasillo en todo lo guardado, dejando cada anaquel donde está. */
        trasladarPasillo(anterior, nueva) {
            const anaqueles = this.disposicion.anaqueles.filter((a) => `${a.zona}-${dosDigitos(a.pasillo)}` === anterior);
            const cambiar = (clave) => (clave.startsWith(`${anterior}-`) ? nueva + clave.slice(anterior.length) : clave);
            if (anterior in this.config.pasillos) {
                this.config.pasillos[nueva] = this.config.pasillos[anterior];
                delete this.config.pasillos[anterior];
            }
            this.config.quitados = this.config.quitados.map(cambiar);
            this.config.extras = this.config.extras.map(cambiar);
            for (const a of anaqueles) {
                delete this.config.disposicion[a.clave];
                this.fijar({ clave: cambiar(a.clave) }, a.x, a.z, a.giro);
            }
        },

        /** Lista del formulario: cambiar la zona o el número de un pasillo registrado. */
        renombrarPasillo(clave, zona, numero) {
            zona = String(zona).trim().toUpperCase();
            numero = Number(numero);
            const nueva = `${zona}-${dosDigitos(numero)}`;
            this.avisoPlano = '';
            if (nueva === clave) {
                return;
            }
            if (!/^[A-Z]{2}$/.test(zona) || !(numero >= 1 && numero <= 99)) {
                this.avisoPlano = 'La zona son 2 letras (por ejemplo PK) y el pasillo un número del 1 al 99.';
            } else if (this.existePasillo(zona, numero)) {
                this.avisoPlano = `Ya hay un pasillo ${dosDigitos(numero)} en ${zona}.`;
            } else {
                this.trasladarPasillo(clave, nueva);
                return;
            }
            // Se deja el valor que tenía: la lista se vuelve a dibujar desde config.
            this.config.pasillos = { ...this.config.pasillos };
        },

        quitarPasillo(clave) {
            if (!confirm(`¿Quitar el pasillo ${clave}? Sus anaqueles sin productos salen del plano al guardar.`)) {
                return;
            }
            delete this.config.pasillos[clave];
            this.config.extras = this.config.extras.filter((anaquel) => !anaquel.startsWith(`${clave}-`));
        },

        /** Al tocar un anaquel se abre el cuadro para ponerle el número de pasillo (y de anaquel). */
        abrirRenumerar() {
            const a = this.elegido;
            if (!a) {
                this.renumero = null;
                return;
            }
            this.nuevo = null;
            this.avisoPlano = '';
            if (esTelefono()) {
                // Sin teclado: los números se proponen y se ajustan con − y +.
                this.enfocado = true;
                const { zona, pasillo, anaquel, modo } = this.ultimo ?? {};
                this.renumero = {
                    zona: zona ?? a.zona,
                    pasillo: Math.min(pasillo === undefined ? a.pasillo : pasillo + (modo === 'pasillo' ? 1 : 0), 99),
                    anaquel: Math.min(modo === 'anaquel' ? anaquel + 1 : a.anaquel, 99),
                };
                return;
            }
            // Los números empiezan vacíos para escribirlos; el actual se ve en el título del cuadro.
            this.renumero = { zona: a.zona, pasillo: '', anaquel: '' };
        },

        cambiarNumero(campo, paso) {
            this.renumero[campo] = Math.min(Math.max((Number(this.renumero[campo]) || 0) + paso, 1), 99);
        },

        /**
         * Asigna el número del cuadro: modo 'pasillo' cambia la zona y el número de la fila del
         * anaquel elegido; modo 'anaquel' pasa solo ese anaquel al pasillo y número del cuadro.
         */
        renumerar(modo = this.moverPasillo ? 'pasillo' : 'anaquel') {
            const a = this.elegido;
            if (!a || !this.renumero) {
                return;
            }
            if (this.ejemplo) {
                this.avisoPlano = 'Este es un almacén de ejemplo: para cambiar números, primero tócale «Usar este ejemplo como mi almacén».';
                return;
            }
            const zona = String(this.renumero.zona).trim().toUpperCase();
            const todoElPasillo = modo === 'pasillo';
            // «Solo este anaquel» sin pasillo escrito: el anaquel se queda en su pasillo.
            const numero = this.renumero.pasillo === '' && !todoElPasillo ? a.pasillo : Number(this.renumero.pasillo);
            const nuevoAnaquel = Number(this.renumero.anaquel);
            if (todoElPasillo ? this.renumero.pasillo === '' : this.renumero.anaquel === '') {
                this.avisoPlano = todoElPasillo ? 'Escribe el número de pasillo.' : 'Escribe el número de anaquel.';
                return;
            }
            if (!/^[A-Z]{2}$/.test(zona) || !(numero >= 1 && numero <= 99) || (!todoElPasillo && !(nuevoAnaquel >= 1 && nuevoAnaquel <= 99))) {
                this.avisoPlano = 'La zona son 2 letras (por ejemplo PK) y el pasillo y el anaquel, números del 1 al 99.';
                return;
            }
            const grupo = todoElPasillo ? this.filaDe(a) : [a];
            if (grupo.some((b) => ocupados.has(b.clave))) {
                this.avisoPlano = 'Tiene productos ubicados: su número sale de las ubicaciones de esos productos. Cámbiales la ubicación primero.';
                return;
            }

            if (todoElPasillo && grupo.length < this.pasilloDe(a).length) {
                // Solo la fila tocada: cada anaquel pasa al pasillo nuevo con su mismo número.
                const nuevas = new Map(grupo.map((b) => [b, claveAnaquel(zona, numero, b.anaquel)]));
                if ([...nuevas].every(([b, nueva]) => b.clave === nueva)) {
                    this.renumero = null;
                    return;
                }
                const choque = [...nuevas.values()].find((nueva) => this.disposicion.anaqueles.some((b) => b.clave === nueva && !nuevas.has(b)));
                if (choque) {
                    this.avisoPlano = `Ya hay un anaquel ${choque}: cámbiale el número a ese anaquel o a los de esta fila primero.`;
                    return;
                }
                for (const [b, nueva] of nuevas) {
                    this.cambiarClave(b, nueva);
                }
                this.seleccionado = nuevas.get(a);
            } else if (todoElPasillo) {
                const anterior = `${a.zona}-${dosDigitos(a.pasillo)}`;
                const nueva = `${zona}-${dosDigitos(numero)}`;
                if (nueva === anterior) {
                    this.renumero = null;
                    return;
                }
                if (this.existePasillo(zona, numero)) {
                    this.avisoPlano = `Ya hay un pasillo ${dosDigitos(numero)} en ${zona}.`;
                    return;
                }
                this.trasladarPasillo(anterior, nueva);
                this.moverPasillo = true;
                this.seleccionado = nueva + a.clave.slice(anterior.length);
            } else {
                const nueva = claveAnaquel(zona, numero, nuevoAnaquel);
                if (nueva === a.clave) {
                    this.renumero = null;
                    return;
                }
                if (this.disposicion.anaqueles.some((b) => b.clave === nueva)) {
                    this.avisoPlano = `Ya hay un anaquel ${nueva}.`;
                    return;
                }
                this.cambiarClave(a, nueva);
                this.seleccionado = nueva;
            }
            this.renumero = null;
            this.avisoPlano = '';
            if (this.enfocado) {
                // Queda libre para tocar el que sigue: uno elegido se agarraría para moverlo.
                this.ultimo = { zona, pasillo: numero, anaquel: nuevoAnaquel, modo };
                this.avisoPlano = modo === 'pasillo'
                    ? `Fila asignada al pasillo ${dosDigitos(numero)}. Toca la que sigue.`
                    : `Anaquel ${claveAnaquel(zona, numero, nuevoAnaquel)} asignado. Toca el que sigue.`;
                this.seleccionado = null;
            }
        },

        /** Le pone otro número a un anaquel; se queda en el mismo lugar del almacén. */
        cambiarClave(a, nueva) {
            const lugar = { x: a.x, z: a.z, giro: a.giro };
            this.sacar([a.clave]);
            // Si el número nuevo es de un pasillo registrado y estaba borrado, vuelve; si no, queda suelto.
            if (this.config.quitados.includes(nueva)) {
                this.config.quitados = this.config.quitados.filter((clave) => clave !== nueva);
            } else {
                this.config.extras = [...this.config.extras, nueva].sort();
            }
            this.fijar({ clave: nueva }, lugar.x, lugar.z, lugar.giro);
        },

        /** Convierte el almacén de ejemplo en pasillos registrados, con todo lo que ya se acomodó. */
        usarEjemplo() {
            if (!confirm('¿Usar el almacén de ejemplo como el de esta droguería? Sus pasillos quedan registrados y luego los puedes cambiar.')) {
                return;
            }
            for (const { zona, pasillos } of planoEjemplo().zonas) {
                for (const { pasillo: numero, anaqueles } of pasillos) {
                    const maximo = Math.max(...anaqueles.map((a) => a.anaquel));
                    this.config.pasillos[`${zona}-${dosDigitos(numero)}`] = {
                        izquierda: Math.ceil(maximo / 2),
                        derecha: Math.floor(maximo / 2),
                        estantes: anaqueles[0].estantes,
                        posiciones: anaqueles[0].posiciones,
                    };
                }
            }
        },

        /** Flechas del teclado: mueven 10 cm el anaquel elegido (o su fila); R lo gira. */
        teclado(evento) {
            const a = this.elegido;
            const pasos = { ArrowLeft: [-0.1, 0], ArrowRight: [0.1, 0], ArrowUp: [0, -0.1], ArrowDown: [0, 0.1] };
            const e = this.entradaElegida;
            if (e && pasos[evento.key]) {
                evento.preventDefault();
                e.x = redondear(e.x + pasos[evento.key][0]);
                e.z = redondear(e.z + pasos[evento.key][1]);
                return;
            }
            if (!a && !e) {
                return;
            }
            if (evento.key === 'r' || evento.key === 'R') {
                evento.preventDefault();
                return this.girar();
            }
            if (evento.key === 'Delete' || evento.key === 'Backspace') {
                evento.preventDefault();
                return this.borrar();
            }
            const paso = pasos[evento.key];
            if (paso) {
                evento.preventDefault();
                for (const b of this.grupoDe(a)) {
                    this.fijar(b, b.x + paso[0], b.z + paso[1], b.giro);
                }
            }
        },

        etiquetaAnaquel(a) {
            return `P${dosDigitos(a.pasillo)}·${dosDigitos(a.anaquel)}`;
        },
    };
}
