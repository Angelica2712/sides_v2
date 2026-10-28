/**
 * Picking en vivo (resources/views/monitor/picking.blade.php), para el supervisor.
 *
 * Una tarjeta por despachador con su pedido, su avance y el producto que busca, y en el Almacén
 * 3D cada uno marcado con su color en esa ubicación. Tocar una tarjeta lleva la cámara a ese
 * despachador y muestra con la flecha morada el producto que le sigue.
 *
 * Como el monitor, no se refresca por tiempo: escucha sides-monitor.{codisb}. «picking»
 * (App\Events\PickingAvanzo) llega cada vez que alguien guarda una cantidad y trae el
 * movimiento para la lista; «actualizado» (MonitorActualizado) cuando un pedido entra o sale
 * del picking. En los dos casos se vuelve a pedir el avance de todos. Con el socket caído se
 * consulta cada 30 segundos.
 */
import { crearVisor } from './almacen-3d';
import { describir, todas } from './ubicacion';

const MS_SIN_CONEXION = 30000;
const MS_AGRUPAR_AVISOS = 250;
const MAXIMO_MOVIMIENTOS = 30;
const COLORES = ['#2563eb', '#16a34a', '#db2777', '#ca8a04', '#0891b2', '#dc2626', '#7c3aed', '#ea580c', '#0d9488', '#4f46e5'];

const hora = () => new Date().toLocaleTimeString('es-VE', { hour12: false });

export default function pickingVivo({ canal, urls }) {
    // Visor 3D fuera del estado de Alpine: three.js no soporta sus proxies.
    let visor = null;
    // El visor se crea una sola vez aunque lleguen dos avisos mientras carga el plano.
    let creando = null;
    let agrupador = null;
    // Color fijo por despachador mientras la pantalla esté abierta, aunque otros entren o salgan.
    const colores = new Map();
    // Lo último que se enfocó del despachador elegido: si cambia, la cámara lo sigue.
    let enfocado = null;

    return {
        operarios: [],
        movimientos: [],
        elegido: null,
        conectado: false,
        hubo: false,
        cargando: true,
        actualizando: false,
        hora: '',
        mensajeMapa: '',
        planoAlmacen: null,
        conMapa: Boolean(urls.plano),

        get seleccion() {
            return this.operarios.find((o) => o.pedido === this.elegido) ?? null;
        },
        get detalleSeleccion() {
            return describir(todas(this.seleccion?.actual?.ubicacion)[0] ?? null, this.planoAlmacen);
        },
        get unidadesEnCurso() {
            return this.operarios.reduce((total, o) => total + o.unidades, 0);
        },
        get unidadesListas() {
            return this.operarios.reduce((total, o) => total + o.despachadas, 0);
        },

        init() {
            this.$watch('elegido', () => {
                // undefined (no null): al soltar al elegido hay que limpiar su resaltado.
                enfocado = undefined;
                this.pintar();
            });
            this.refrescar();
            this.escuchar();
            setInterval(() => {
                if (!this.conectado) {
                    this.refrescar();
                }
            }, MS_SIN_CONEXION);
            this.$el.addEventListener('alpine:destroy', () => visor?.destruir());
        },

        escuchar() {
            if (!window.Echo) {
                return;
            }
            window.Echo.private(canal)
                .listen('.picking', (aviso) => {
                    this.movimientos = [{ ...aviso, hora: hora(), id: `${Date.now()}-${Math.random()}` }, ...this.movimientos].slice(0, MAXIMO_MOVIMIENTOS);
                    this.avisar();
                })
                .listen('.actualizado', () => this.avisar())
                .subscribed(() => {
                    this.conectado = true;
                    this.hubo = true;
                    this.refrescar();
                })
                .error(() => {
                    this.conectado = false;
                });
            window.Echo.connector.pusher?.connection.bind('state_change', ({ current }) => {
                if (current !== 'connected') {
                    this.conectado = false;
                }
            });
        },

        avisar() {
            clearTimeout(agrupador);
            agrupador = setTimeout(() => this.refrescar(), MS_AGRUPAR_AVISOS);
        },

        async refrescar() {
            if (this.actualizando) {
                return;
            }
            this.actualizando = true;
            try {
                const { data } = await window.axios.get(urls.datos);
                for (const o of data.operarios) {
                    if (!colores.has(o.despachador)) {
                        colores.set(o.despachador, COLORES[colores.size % COLORES.length]);
                    }
                    o.color = colores.get(o.despachador);
                }
                this.operarios = data.operarios;
                this.hora = data.hora;
                if (this.elegido !== null && !this.seleccion) {
                    this.elegido = null;
                }
                await this.pintar();
            } catch (error) {
                // Sin red: queda lo último que se vio y se reintenta al reconectar.
                if (error.response?.status === 401 || error.response?.status === 419) {
                    window.location.reload();
                }
            } finally {
                this.actualizando = false;
                this.cargando = false;
            }
        },

        /** Marca a todos en el mapa y, si hay uno elegido, su producto y el que le sigue. */
        async pintar() {
            if (!this.conMapa) {
                return;
            }
            if (!visor) {
                creando ??= (async () => {
                    const { data } = await window.axios.get(urls.plano);
                    this.planoAlmacen = data;
                    visor = await crearVisor(this.$refs.visor, data);
                })();
                try {
                    await creando;
                } catch (error) {
                    creando = null;
                    this.mensajeMapa = 'No se pudo cargar el almacén.';
                    return;
                }
            }
            visor.equipo(this.operarios
                .filter((o) => o.actual)
                .map((o) => ({
                    ubicacion: todas(o.actual.ubicacion)[0] ?? null,
                    nombre: o.despachador,
                    color: o.color,
                    soloNombre: o.pedido === this.elegido,
                })));
            this.enfocar();
        },

        /**
         * Con un despachador elegido: su ubicación resaltada y la flecha al siguiente. La cámara
         * vuela solo cuando se elige o cuando él pasa a otro producto, no con cada aviso.
         */
        enfocar() {
            if (!visor) {
                return;
            }
            const o = this.seleccion;
            const clave = o ? `${o.pedido}:${o.actual?.ubicacion ?? ''}:${o.siguiente?.ubicacion ?? ''}` : null;
            const volar = clave !== null && clave.split(':')[1] !== enfocado?.split(':')[1];
            if (clave === enfocado) {
                return;
            }
            enfocado = clave;
            this.mensajeMapa = '';
            if (!o?.actual) {
                visor.resaltar(null, [], { volar: false });
                return;
            }
            const ubicacion = todas(o.actual.ubicacion)[0] ?? null;
            const siguiente = todas(o.siguiente?.ubicacion)[0] ?? null;
            if (!ubicacion) {
                visor.resaltar(null, [], { volar: false });
                this.mensajeMapa = `${o.despachador} busca un producto sin ubicación del almacén registrada.`;
                return;
            }
            if (!visor.resaltar(ubicacion, [], { volar, siguiente })) {
                this.mensajeMapa = `La ubicación ${ubicacion.codigo} no está en el plano del almacén.`;
            }
        },

        elegir(o) {
            this.elegido = this.elegido === o.pedido ? null : o.pedido;
            if (this.elegido !== null) {
                this.$nextTick(() => this.$refs.mapa?.scrollIntoView({ behavior: 'smooth', block: 'nearest' }));
            }
        },

        verTodo() {
            this.elegido = null;
            visor?.vistaGeneral();
        },

        navegar3d(accion) {
            visor?.navegar(accion);
        },

        porcentaje(o) {
            return o.productos ? Math.round((o.revisados / o.productos) * 100) : 0;
        },

        codigo(ubicacion) {
            return (ubicacion || '').split(';')[0].split('/').pop() || 'Sin ubicación';
        },
    };
}
