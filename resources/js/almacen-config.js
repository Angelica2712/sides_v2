/**
 * Acomodo del almacén (resources/views/almacen/acomodo.blade.php, desde Administración o desde
 * Almacén 3D > Ajustes avanzados): el formulario, una vista previa 3D que se redibuja con cada
 * cambio (con una ubicación de prueba resaltada) y el plano visto desde arriba, donde se arrastran,
 * giran y borran los anaqueles para dejarlos como están en la droguería (config.disposicion y
 * config.quitados). Un anaquel con productos ubicados (plano.ocupados) no se puede borrar.
 *
 * El visor vive fuera del estado de Alpine: los objetos de three.js no soportan los proxies reactivos.
 */
import { crearVisor, planoEjemplo } from './almacen-3d';
import { claveAnaquel, disponer, enPlano, limitesDe } from './almacen-plano';
import { describir, interpretar, nombreZona } from './ubicacion';

const dosDigitos = (numero) => String(numero).padStart(2, '0');
const redondear = (metros) => Math.round(metros * 10) / 10;
const COLORES_ZONA = ['#2563eb', '#16a34a', '#9333ea', '#db2777', '#0891b2', '#ca8a04', '#dc2626'];

export default function almacenConfig({ plano, config }) {
    let visor = null;
    let espera = null;
    // Dedos apoyados en el plano (pointerId => [x, y]), para el zoom con dos dedos en el teléfono.
    const dedos = new Map();
    const distanciaDedos = () => {
        const [[x1, y1], [x2, y2]] = [...dedos.values()];
        return Math.hypot(x2 - x1, y2 - y1);
    };
    // Sin ubicaciones en el formato se muestra el almacén de ejemplo con este acomodo. El plano
    // trae también los anaqueles borrados; se filtran con config.quitados (ver sinQuitados).
    const base = plano.zonas.length ? plano : planoEjemplo();
    const ocupados = new Set(plano.ocupados ?? []);
    const primera = base.zonas.find((zona) => zona.pasillos.length);
    const pasillo = primera?.pasillos[0];
    const anaquel = pasillo?.anaqueles.find((a) => a.anaquel === 3) ?? pasillo?.anaqueles[0];
    const coloresZona = Object.fromEntries(base.zonas.map(({ zona }, i) => [zona, COLORES_ZONA[i % COLORES_ZONA.length]]));
    // PHP manda [] cuando no hay ninguno; un arreglo perdería las claves al pasarlo a JSON.
    config.disposicion = Array.isArray(config.disposicion) ? {} : config.disposicion;
    config.quitados = Array.isArray(config.quitados) ? config.quitados : [];
    config.entradas = Array.isArray(config.entradas) ? config.entradas : [];
    // Pasillos registrados a mano ("PK-03"); los demás salen de las ubicaciones de los productos.
    const registrados = new Set(Array.isArray(config.pasillos) ? [] : Object.keys(config.pasillos ?? {}));

    return {
        config,
        ejemplo: !plano.zonas.length,
        prueba: primera ? `${primera.zona}-${dosDigitos(pasillo.pasillo)}-${dosDigitos(anaquel.anaquel)}-A-01` : '',
        mensaje: '',
        // Por qué no se pudo borrar lo elegido en el plano.
        avisoPlano: '',
        vista: '3d',

        // Plano: anaquel elegido (o, en su lugar, la entrada elegida, por su índice en
        // config.entradas), si se mueve todo su pasillo, arrastre en curso y lo que se ve.
        seleccionado: null,
        entrada: null,
        moverPasillo: false,
        arrastre: null,
        caja: [0, 0, 10, 10],

        /** El plano sin los anaqueles borrados (los que tienen productos se quedan siempre). */
        get sinQuitados() {
            const quitados = new Set(this.config.quitados.filter((clave) => !ocupados.has(clave)));
            if (!quitados.size) {
                return base;
            }
            return {
                ...base,
                zonas: base.zonas.map((zona) => ({
                    ...zona,
                    pasillos: zona.pasillos
                        .map((p) => ({ ...p, anaqueles: p.anaqueles.filter((a) => !quitados.has(claveAnaquel(zona.zona, p.pasillo, a.anaquel))) }))
                        .filter((p) => p.anaqueles.length),
                })),
            };
        },
        get planoPrevio() {
            return { ...this.sinQuitados, config: JSON.parse(JSON.stringify(this.config)) };
        },
        get detalle() {
            return describir(interpretar(this.prueba), this.planoPrevio);
        },
        get disposicion() {
            return disponer(this.sinQuitados, this.config);
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

        init() {
            this.$watch('config', () => {
                clearTimeout(espera);
                espera = setTimeout(() => this.dibujar(), 250);
            });
            this.encajar();
            this.dibujar();
            this.$el.addEventListener('alpine:destroy', () => visor?.destruir());
        },

        async dibujar() {
            visor?.destruir();
            visor = null;
            try {
                visor = await crearVisor(this.$refs.visor, this.planoPrevio);
            } catch (error) {
                this.mensaje = 'No se pudo dibujar el almacén en este equipo.';
                console.error(error);
                return;
            }
            this.resaltar();
        },

        navegar3d(accion) {
            visor?.navegar(accion);
        },

        resaltar() {
            const ubicacion = interpretar(this.prueba);
            this.mensaje = '';
            if (!visor || !ubicacion) {
                return;
            }
            if (!visor.resaltar(ubicacion)) {
                this.mensaje = `La ubicación ${ubicacion.codigo} no está en el plano.`;
            }
        },

        // ---- Plano visto desde arriba ----

        nombreZona(zona) {
            return nombreZona(zona, this.config);
        },
        colorZona(zona) {
            return coloresZona[zona] ?? '#475569';
        },

        /** Contenido del plano (zonas sin pasillos y anaqueles). Alpine no repite elementos dentro de un SVG. */
        get svgPlano() {
            const texto = (valor) => String(valor).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);
            const zonas = this.disposicion.zonasSolas.map((z) => `
                <rect x="${z.x - z.ancho / 2}" y="${z.z - z.fondo / 2}" width="${z.ancho}" height="${z.fondo}"
                      fill="#e2e8f0" stroke="#94a3b8" stroke-width="0.05" stroke-dasharray="0.2 0.15"></rect>
                <text x="${z.x}" y="${z.z}" font-size="0.4" text-anchor="middle" dominant-baseline="middle" fill="#475569"
                      font-weight="700">${texto(`${z.zona} · ${this.nombreZona(z.zona)}`)}</text>`);
            const anaqueles = this.anaqueles.map((a) => {
                const elegido = a.clave === this.seleccionado;
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

        /** Claves que se mueven o giran juntas: el anaquel, o todo su pasillo. */
        grupoDe(a) {
            return this.moverPasillo
                ? this.disposicion.anaqueles.filter((otro) => otro.zona === a.zona && otro.pasillo === a.pasillo)
                : [a];
        },

        tomarEntrada(evento, indice) {
            this.entrada = indice;
            this.seleccionado = null;
            this.capturar(evento);
            this.arrastre = { tipo: 'entrada', indice, inicio: this.puntoEnPlano(evento), dx: 0, dz: 0 };
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
            this.prueba = `${a.clave}-A-01`;
            this.resaltar();
            this.capturar(evento);
            this.arrastre = { tipo: 'anaquel', claves: this.grupoDe(a).map((b) => b.clave), inicio: this.puntoEnPlano(evento), dx: 0, dz: 0 };
        },

        /** Arrastrar el fondo desplaza la vista. */
        tomarFondo(evento) {
            this.seleccionado = null;
            this.entrada = null;
            this.capturar(evento);
            this.arrastre = { tipo: 'vista', inicio: [evento.clientX, evento.clientY], caja: [...this.caja] };
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

        /** Gira 90° el anaquel elegido, o todo su pasillo alrededor de su centro. */
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

        /** Devuelve el anaquel elegido (o su pasillo) a su lugar automático. */
        restaurar() {
            const a = this.elegido;
            if (!a) {
                return;
            }
            for (const b of this.grupoDe(a)) {
                delete this.config.disposicion[b.clave];
            }
        },

        /**
         * Borra el anaquel elegido o, con «Mover el pasillo completo», su pasillo. Un pasillo
         * registrado sin productos se quita también de la lista de pasillos; los anaqueles con
         * productos ubicados se quedan y se avisa por qué.
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
                this.avisoPlano = 'Este es un almacén de ejemplo: todavía no tienes pasillos registrados. Ármalo en «Armar almacén».';
                return;
            }
            const grupo = this.grupoDe(a).map((b) => b.clave);
            const claves = grupo.filter((clave) => !ocupados.has(clave));
            const clavePasillo = `${a.zona}-${dosDigitos(a.pasillo)}`;
            if (!claves.length) {
                this.avisoPlano = registrados.has(clavePasillo)
                    ? 'Tiene productos ubicados: muévelos a otro hueco (en «Ver almacén») o cámbiales la ubicación antes de borrarlo.'
                    : `El pasillo ${dosDigitos(a.pasillo)} sale de las ubicaciones que tienen los productos. Para quitarlo hay que cambiarles la ubicación a esos productos.`;
                return;
            }
            const pasilloEntero = this.moverPasillo && claves.length === grupo.length && registrados.has(clavePasillo);
            const que = pasilloEntero
                ? `el pasillo ${dosDigitos(a.pasillo)} completo`
                : claves.length === 1 ? `el anaquel ${this.etiquetaAnaquel(a)}` : `${claves.length} anaqueles del pasillo ${dosDigitos(a.pasillo)}`;
            if (!confirm(`¿Borrar ${que}? Se quita del plano al guardar el acomodo.`)) {
                return;
            }
            for (const clave of claves) {
                delete this.config.disposicion[clave];
            }
            this.config.quitados = [...new Set([...this.config.quitados, ...claves])].sort();
            if (pasilloEntero) {
                // La lista de pasillos del formulario tiene su propio estado (x-data del fieldset).
                window.dispatchEvent(new CustomEvent('quitar-pasillo', { detail: clavePasillo }));
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

        /** Flechas del teclado: mueven 10 cm el anaquel elegido (o su pasillo); R lo gira. */
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
