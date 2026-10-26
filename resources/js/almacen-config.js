/**
 * Administración > Almacén de una droguería (resources/views/admin/almacen.blade.php): el
 * formulario del acomodo, una vista previa 3D que se redibuja con cada cambio (con una ubicación de
 * prueba resaltada) y el plano visto desde arriba, donde se arrastran y giran los anaqueles para
 * dejarlos como están en la droguería (config.disposicion).
 *
 * El visor vive fuera del estado de Alpine: los objetos de three.js no soportan los proxies reactivos.
 */
import { crearVisor, planoEjemplo } from './almacen-3d';
import { disponer, enPlano, limitesDe } from './almacen-plano';
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
    // Sin ubicaciones en el formato se muestra el almacén de ejemplo con este acomodo.
    const base = plano.zonas.length ? plano : planoEjemplo();
    const primera = base.zonas.find((zona) => zona.pasillos.length);
    const pasillo = primera?.pasillos[0];
    const anaquel = pasillo?.anaqueles.find((a) => a.anaquel === 3) ?? pasillo?.anaqueles[0];
    const coloresZona = Object.fromEntries(base.zonas.map(({ zona }, i) => [zona, COLORES_ZONA[i % COLORES_ZONA.length]]));
    // PHP manda [] cuando no hay ninguno; un arreglo perdería las claves al pasarlo a JSON.
    config.disposicion = Array.isArray(config.disposicion) ? {} : config.disposicion;

    return {
        config,
        ejemplo: !plano.zonas.length,
        prueba: primera ? `${primera.zona}-${dosDigitos(pasillo.pasillo)}-${dosDigitos(anaquel.anaquel)}-A-01` : '',
        mensaje: '',
        vista: '3d',

        // Plano: anaquel elegido, si se mueve todo su pasillo, arrastre en curso y lo que se ve.
        seleccionado: null,
        moverPasillo: false,
        arrastre: null,
        caja: [0, 0, 10, 10],

        get planoPrevio() {
            return { ...base, config: JSON.parse(JSON.stringify(this.config)) };
        },
        get detalle() {
            return describir(interpretar(this.prueba), this.planoPrevio);
        },
        get disposicion() {
            return disponer(base, this.config);
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
            return [...zonas, ...anaqueles].join('');
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

        tomar(evento, a) {
            this.seleccionado = a.clave;
            this.prueba = `${a.clave}-A-01`;
            this.resaltar();
            this.capturar(evento);
            this.arrastre = { tipo: 'anaquel', claves: this.grupoDe(a).map((b) => b.clave), inicio: this.puntoEnPlano(evento), dx: 0, dz: 0 };
        },

        /** Arrastrar el fondo desplaza la vista. */
        tomarFondo(evento) {
            this.seleccionado = null;
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
            if (arrastre?.tipo !== 'anaquel' || (!arrastre.dx && !arrastre.dz)) {
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
            if (!a) {
                return;
            }
            if (evento.key === 'r' || evento.key === 'R') {
                evento.preventDefault();
                return this.girar();
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
