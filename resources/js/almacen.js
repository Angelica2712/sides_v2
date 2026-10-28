/**
 * Pantalla Almacén 3D (resources/views/almacen/index.blade.php): el plano de la droguería y un
 * buscador por producto o por ubicación. Con permiso de ubicaciones (urls.guardar), la caja
 * naranja del producto elegido se arrastra a otro hueco y, al confirmar, esa pasa a ser su
 * ubicación de picking (las demás que tenga se conservan).
 *
 * El visor vive fuera del estado de Alpine: los objetos de three.js no soportan los proxies reactivos.
 */
import { crearVisor, planoEjemplo } from './almacen-3d';
import { describir, formatear, interpretar, nombreFormato } from './ubicacion';

export default function almacenVista({ plano, urls }) {
    let visor = null;
    let espera = null;

    return {
        plano,
        ejemplo: false,
        cargando: false,
        buscar: '',
        productos: [],
        buscando: false,
        elegido: null,
        ubicacion: null,
        // Plano que se está viendo (el de la droguería o el de ejemplo).
        mostrado: null,
        mensaje: '',
        aviso: '',
        puedeMover: Boolean(urls.guardar),
        // Producto soltado en otro hueco, esperando que se confirme: { desde, hacia }.
        movimiento: null,
        guardando: false,

        get arrastrable() {
            return this.puedeMover && !this.ejemplo && Boolean(this.elegido?.ubicaciones.length);
        },

        get vacio() {
            return this.plano.zonas.length === 0;
        },
        get detalle() {
            return describir(this.ubicacion, this.mostrado);
        },

        init() {
            if (!this.vacio) {
                this.montar(this.plano);
            }
            this.$el.addEventListener('alpine:destroy', () => visor?.destruir());
        },

        async montar(plano) {
            this.cargando = true;
            this.mostrado = plano;
            visor?.destruir();
            try {
                visor = await crearVisor(this.$refs.visor, plano);
            } catch (error) {
                this.mensaje = 'No se pudo dibujar el almacén en este equipo.';
                console.error(error);
            } finally {
                this.cargando = false;
            }
        },

        verEjemplo() {
            this.ejemplo = true;
            this.$nextTick(() => this.montar({ ...planoEjemplo(), config: this.plano.config }).then(() => this.ir(formatear({ zona: 'PK', pasillo: 2, anaquel: 7, estante: 'C', posicion: 3 }))));
        },

        /** Con un código de ubicación se va directo; con otro texto se buscan productos. */
        alEscribir() {
            clearTimeout(espera);
            this.mensaje = '';
            const texto = this.buscar.trim();
            if (interpretar(texto)) {
                this.productos = [];
                return;
            }
            espera = setTimeout(() => this.buscarProductos(texto), 300);
        },

        enviar() {
            const ubicacion = interpretar(this.buscar);
            if (ubicacion) {
                this.elegido = null;
                return this.ir(ubicacion.codigo);
            }
            if (this.productos.length === 1) {
                this.elegir(this.productos[0]);
            }
        },

        async buscarProductos(texto) {
            if (texto.length < 2 || this.ejemplo) {
                this.productos = [];
                return;
            }
            this.buscando = true;
            try {
                const { data } = await window.axios.get(urls.buscar, { params: { q: texto } });
                this.productos = data.productos;
                if (!data.productos.length) {
                    this.mensaje = 'No hay productos con ese código o descripción.';
                }
            } catch {
                this.mensaje = 'No se pudo buscar. Revisa la conexión.';
            } finally {
                this.buscando = false;
            }
        },

        elegir(producto) {
            this.elegido = producto;
            this.productos = [];
            this.aviso = '';
            this.cancelarMovimiento();
            if (!producto.ubicaciones.length) {
                this.ubicacion = null;
                visor?.vistaGeneral();
                this.mensaje = producto.ubicacion
                    ? `La ubicación "${producto.ubicacion}" no sigue el formato ${nombreFormato()}.`
                    : 'Este producto no tiene ubicación en el catálogo.';
                return;
            }
            const [principal, ...otras] = producto.ubicaciones;
            this.ir(principal, otras);
        },

        ir(codigo, otras = []) {
            this.ubicacion = interpretar(codigo);
            this.mensaje = '';
            if (visor && !visor.resaltar(codigo, otras)) {
                visor.permitirMover(null);
                this.mensaje = `La ubicación ${codigo} no está en el plano de este almacén.`;
                return;
            }
            visor?.permitirMover(this.arrastrable ? (hacia) => this.alSoltar(hacia) : null);
            // En el teléfono el 3D queda debajo del buscador: se baja hasta él.
            if (window.innerWidth < 1024) {
                this.$nextTick(() => this.$refs.visor.scrollIntoView({ behavior: 'smooth', block: 'end' }));
            }
        },

        vistaGeneral() {
            visor?.vistaGeneral();
        },

        navegar3d(accion) {
            visor?.navegar(accion);
        },

        // ---- Mover el producto a otro hueco ----

        alSoltar(hacia) {
            const desde = this.elegido?.ubicaciones[0];
            if (!desde || hacia === desde) {
                this.cancelarMovimiento();
                return;
            }
            this.movimiento = { desde, hacia };
            this.mensaje = '';
        },

        cancelarMovimiento() {
            this.movimiento = null;
            visor?.limpiarDestino();
        },

        async confirmarMovimiento() {
            if (!this.movimiento || this.guardando) {
                return;
            }
            const { hacia } = this.movimiento;
            const otras = this.elegido.ubicaciones.slice(1).filter((codigo) => codigo !== hacia);
            this.guardando = true;
            try {
                await window.axios.put(urls.guardar, { codprod: this.elegido.codprod, ubicacion: [hacia, ...otras].join(';') });
                this.elegido = { ...this.elegido, ubicacion: [hacia, ...otras].join(';'), ubicaciones: [hacia, ...otras] };
                this.movimiento = null;
                this.aviso = `${this.elegido.desprod} quedó en ${hacia}.`;
                this.ir(hacia, otras);
            } catch (error) {
                const datos = error.response?.data;
                this.mensaje = Object.values(datos?.errors ?? {})[0]?.[0] ?? datos?.message ?? 'No se pudo guardar. Revisa la conexión.';
            } finally {
                this.guardando = false;
            }
        },
    };
}
