/**
 * Pantalla Almacén 3D (resources/views/almacen/index.blade.php): el plano de la droguería y un
 * buscador por producto o por ubicación.
 *
 * El visor vive fuera del estado de Alpine: los objetos de three.js no soportan los proxies reactivos.
 */
import { crearVisor, planoEjemplo } from './almacen-3d';
import { describir, interpretar } from './ubicacion';

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
            this.$nextTick(() => this.montar({ ...planoEjemplo(), config: this.plano.config }).then(() => this.ir('PK-02-07-C-03')));
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
            if (!producto.ubicaciones.length) {
                this.ubicacion = null;
                visor?.vistaGeneral();
                this.mensaje = producto.ubicacion
                    ? `La ubicación "${producto.ubicacion}" no sigue el formato ZONA-PASILLO-ANAQUEL-ESTANTE-POSICIÓN.`
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
                this.mensaje = `La ubicación ${codigo} no está en el plano de este almacén.`;
                return;
            }
            // En el teléfono el 3D queda debajo del buscador: se baja hasta él.
            if (window.innerWidth < 1024) {
                this.$nextTick(() => this.$refs.visor.scrollIntoView({ behavior: 'smooth', block: 'end' }));
            }
        },

        vistaGeneral() {
            visor?.vistaGeneral();
        },
    };
}
