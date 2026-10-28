/**
 * Almacén 3D > Armar almacén (resources/views/almacen/armar.blade.php): asistente para armar el
 * almacén caminándolo con el teléfono. Se elige la zona y, pasillo por pasillo, se cuenta lo que
 * se ve desde la entrada; un dibujo del pasillo y del anaquel muestra cómo queda numerado con el
 * formato de ubicación de la droguería. Cada pasillo se guarda al terminarlo.
 */
import { formatear } from './ubicacion';

const dosDigitos = (numero) => String(numero).padStart(2, '0');

function mensajeDeError(error) {
    const datos = error.response?.data;
    return Object.values(datos?.errors ?? {})[0]?.[0] ?? datos?.message ?? 'No se pudo guardar. Revisa la conexión e intenta de nuevo.';
}

export default function armarAlmacen({ zonas, pasillos, medidas, maximoPorLado, otraNumeracion, urls }) {
    const LIMITES = {
        pasillo: [1, 99],
        izquierda: [0, maximoPorLado],
        derecha: [0, maximoPorLado],
        estantes: [1, 12],
        posiciones: [1, 30],
    };

    return {
        zonas,
        pasillos,
        otraNumeracion,
        paso: pasillos.length ? 'resumen' : 'inicio',

        zona: 'PK',
        nombreZona: '',
        codigoNuevo: '',
        nombreNuevo: '',
        // Pasillo que se está contando y, al editar uno, su clave anterior.
        actual: null,
        antes: null,

        guardando: false,
        error: '',
        aviso: '',

        get porZona() {
            const grupos = {};
            for (const pasillo of this.pasillos) {
                (grupos[pasillo.zona] ??= []).push(pasillo);
            }
            return Object.keys(grupos).sort().map((zona) => ({
                zona,
                nombre: this.nombre(zona),
                pasillos: grupos[zona].sort((a, b) => a.pasillo - b.pasillo),
                anaqueles: grupos[zona].reduce((total, p) => total + p.izquierda + p.derecha, 0),
            }));
        },
        get totalAnaqueles() {
            return this.pasillos.reduce((total, p) => total + p.izquierda + p.derecha, 0);
        },

        // ---- Dibujos del pasillo que se está contando ----

        get izquierdos() {
            return Array.from({ length: this.actual?.izquierda ?? 0 }, (_, i) => dosDigitos(2 * i + 1));
        },
        get derechos() {
            return Array.from({ length: this.actual?.derecha ?? 0 }, (_, i) => dosDigitos(2 * i + 2));
        },
        /** Letras de los estantes de arriba hacia abajo (A es el de abajo). */
        get estantes() {
            const cantidad = this.actual?.estantes ?? 0;
            return Array.from({ length: cantidad }, (_, i) => String.fromCharCode(65 + cantidad - 1 - i));
        },
        get huecos() {
            return Array.from({ length: this.actual?.posiciones ?? 0 }, (_, i) => i + 1);
        },
        get clave() {
            return this.actual ? `${this.zona}-${dosDigitos(this.actual.pasillo)}` : '';
        },
        get ejemplo() {
            const primero = this.actual?.izquierda ? 1 : 2;
            return this.actual ? formatear({ zona: this.zona, pasillo: this.actual.pasillo, anaquel: primero, estante: 'A', posicion: 1 }) : '';
        },
        /** Ya hay otro pasillo registrado con ese número en la zona: se reemplaza. */
        get repetido() {
            return this.pasillos.some((p) => p.clave === this.clave && p.clave !== this.antes);
        },

        nombre(codigo) {
            return this.zonas[codigo] || codigo;
        },

        // ---- Pasos ----

        empezar() {
            this.paso = 'zona';
            this.error = '';
            this.arriba();
        },

        elegirZona(codigo) {
            this.zona = codigo;
            this.nombreZona = '';
            this.nuevoPasillo();
        },

        crearZona() {
            const codigo = this.codigoNuevo.trim().toUpperCase();
            if (!/^[A-Z]{2}$/.test(codigo)) {
                this.error = 'El código de la zona son 2 letras, por ejemplo QX.';
                return;
            }
            this.zona = codigo;
            this.nombreZona = this.nombreNuevo.trim();
            this.codigoNuevo = '';
            this.nombreNuevo = '';
            this.nuevoPasillo();
        },

        /** El siguiente pasillo de la zona, con las medidas del anterior para no contar todo de nuevo. */
        nuevoPasillo() {
            const suyos = this.pasillos.filter((p) => p.zona === this.zona);
            const ultimo = suyos.reduce((mayor, p) => (!mayor || p.pasillo > mayor.pasillo ? p : mayor), null);
            this.antes = null;
            this.actual = {
                pasillo: Math.min((ultimo?.pasillo ?? 0) + 1, 99),
                izquierda: ultimo?.izquierda ?? 5,
                derecha: ultimo?.derecha ?? 5,
                estantes: ultimo?.estantes ?? medidas.estantes,
                posiciones: ultimo?.posiciones ?? medidas.posiciones,
            };
            this.error = '';
            this.paso = 'pasillo';
            this.arriba();
        },

        editar(pasillo) {
            this.zona = pasillo.zona;
            this.nombreZona = '';
            this.antes = pasillo.clave;
            const { izquierda, derecha, estantes, posiciones } = pasillo;
            this.actual = { pasillo: pasillo.pasillo, izquierda, derecha, estantes, posiciones };
            this.error = '';
            this.aviso = '';
            this.paso = 'pasillo';
            this.arriba();
        },

        cambiar(campo, paso) {
            const [minimo, maximo] = LIMITES[campo];
            this.actual[campo] = Math.min(Math.max((Number(this.actual[campo]) || 0) + paso, minimo), maximo);
        },

        async guardar(seguir) {
            if (this.guardando) {
                return;
            }
            if (this.actual.izquierda + this.actual.derecha < 1) {
                this.error = 'El pasillo debe tener al menos un anaquel a algún lado.';
                return;
            }
            this.guardando = true;
            this.error = '';
            try {
                const { data } = await window.axios.put(urls.guardar, {
                    zona: this.zona,
                    nombreZona: this.nombreZona,
                    antes: this.antes,
                    ...this.actual,
                });
                this.pasillos = data.pasillos;
                this.zonas = data.zonas;
                this.nombreZona = '';
                this.aviso = `Guardaste el pasillo ${dosDigitos(this.actual.pasillo)} de ${this.nombre(this.zona)}.`;
                if (seguir) {
                    this.nuevoPasillo();
                } else {
                    this.paso = 'resumen';
                    this.arriba();
                }
            } catch (error) {
                this.error = mensajeDeError(error);
            } finally {
                this.guardando = false;
            }
        },

        async quitar(pasillo) {
            if (this.guardando || !confirm(`¿Quitar el pasillo ${dosDigitos(pasillo.pasillo)} de ${this.nombre(pasillo.zona)}?`)) {
                return;
            }
            this.guardando = true;
            this.error = '';
            try {
                const { data } = await window.axios.delete(urls.quitar, { data: { clave: pasillo.clave } });
                this.pasillos = data.pasillos;
                this.aviso = `Quitaste el pasillo ${dosDigitos(pasillo.pasillo)} de ${this.nombre(pasillo.zona)}.`;
                if (!this.pasillos.length) {
                    this.paso = 'inicio';
                }
            } catch (error) {
                this.error = mensajeDeError(error);
            } finally {
                this.guardando = false;
            }
        },

        arriba() {
            this.$nextTick(() => window.scrollTo({ top: 0, behavior: 'smooth' }));
        },
    };
}
