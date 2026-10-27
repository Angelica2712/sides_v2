/**
 * Ubicaciones (resources/views/ubicaciones/index.blade.php): desde el teléfono se busca o escanea
 * un producto y se le arma la ubicación parte por parte (zona, pasillo, anaquel, estante,
 * posición). Lo armado se conserva entre un producto y otro, y la posición avanza sola, para ir
 * recorriendo el anaquel sin volver a escribir todo.
 */
import { describir, interpretar } from './ubicacion';

const dosDigitos = (numero) => String(numero).padStart(2, '0');
const CLAVE_ARMADO = 'sidesUbicacionArmada';
const LIMITES = { pasillo: 99, anaquel: 99, posicion: 99 };

function mensajeDeError(error) {
    const datos = error.response?.data;
    return Object.values(datos?.errors ?? {})[0]?.[0] ?? datos?.message ?? 'No se pudo guardar. Revisa la conexión e intenta de nuevo.';
}

export default function registroUbicaciones({ plano, zonas, recientes, buscar, urls }) {
    let espera = null;
    const estantes = Math.min(Math.max(plano.config?.estantes ?? 4, 4), 26);

    return {
        zonas,
        recientes,
        letras: Array.from({ length: estantes }, (_, i) => String.fromCharCode(65 + i)),

        buscar: buscar ?? '',
        productos: [],
        buscando: false,
        elegido: null,

        zona: 'PK' in zonas ? 'PK' : (Object.keys(zonas)[0] ?? 'PK'),
        pasillo: 1,
        anaquel: 1,
        estante: 'A',
        posicion: 1,
        otras: '',
        siguiente: true,

        guardando: false,
        error: '',
        aviso: '',

        /** Código armado; se corta en la primera parte vacía (el formato admite cortar por la derecha). */
        get codigo() {
            const partes = [this.zona];
            for (const parte of [this.pasillo, this.anaquel, this.estante, this.posicion]) {
                if (!parte) {
                    break;
                }
                partes.push(typeof parte === 'number' ? dosDigitos(parte) : parte);
            }
            return partes.join('-');
        },
        get detalle() {
            return describir(interpretar(this.codigo), plano);
        },
        get ubicacion() {
            return [this.codigo, ...this.otras.split(';').map((parte) => parte.trim()).filter(Boolean)].join(';');
        },

        init() {
            try {
                const armado = JSON.parse(localStorage.getItem(CLAVE_ARMADO) ?? 'null');
                if (armado) {
                    Object.assign(this, {
                        zona: armado.zona ?? this.zona,
                        pasillo: armado.pasillo ?? this.pasillo,
                        anaquel: armado.anaquel ?? this.anaquel,
                        estante: armado.estante ?? this.estante,
                        posicion: armado.posicion ?? this.posicion,
                        siguiente: armado.siguiente ?? this.siguiente,
                    });
                }
            } catch {
                // Sin almacenamiento del navegador se empieza desde PK-01-01-A-01.
            }
            if (this.buscar) {
                this.enviar();
            }
            this.$nextTick(() => this.$refs.buscar?.focus());
        },

        recordar() {
            try {
                const { zona, pasillo, anaquel, estante, posicion, siguiente } = this;
                localStorage.setItem(CLAVE_ARMADO, JSON.stringify({ zona, pasillo, anaquel, estante, posicion, siguiente }));
            } catch {
                // No es grave: solo no se recuerda en el próximo ingreso.
            }
        },

        // ---- Producto ----

        alEscribir() {
            clearTimeout(espera);
            espera = setTimeout(() => this.consultar(), 300);
        },

        async consultar() {
            const texto = this.buscar.trim();
            if (texto.length < 2) {
                this.productos = [];
                return { exacto: false };
            }
            this.buscando = true;
            try {
                const { data } = await window.axios.get(urls.buscar, { params: { q: texto } });
                // Si el texto cambió mientras llegaba la respuesta, se descarta.
                if (texto === this.buscar.trim()) {
                    this.productos = data.productos;
                }
                return data;
            } catch {
                this.error = 'No se pudo buscar el producto. Revisa la conexión.';
                return { exacto: false };
            } finally {
                this.buscando = false;
            }
        },

        /** Enter en el buscador (lo manda también el lector de códigos): con código o barra exactos se elige solo. */
        async enviar() {
            clearTimeout(espera);
            const { exacto } = await this.consultar();
            if (exacto && this.productos.length) {
                this.elegir(this.productos[0]);
            }
        },

        elegir(producto) {
            this.elegido = producto;
            this.productos = [];
            this.error = '';
            this.aviso = '';
        },

        otro() {
            this.elegido = null;
            this.buscar = '';
            this.productos = [];
            this.$nextTick(() => this.$refs.buscar.focus());
        },

        // ---- Ubicación ----

        /** La ubicación (la primera, si son varias) está en el formato de SIDES. */
        valida(texto) {
            return Boolean(interpretar(String(texto ?? '').split(';')[0]));
        },

        /** Carga en el armado una ubicación que el producto ya tiene. */
        usar(texto) {
            const [primera, ...resto] = String(texto).split(';');
            const ubicacion = interpretar(primera);
            if (!ubicacion) {
                return;
            }
            this.zona = ubicacion.zona;
            this.pasillo = ubicacion.pasillo ?? '';
            this.anaquel = ubicacion.anaquel ?? '';
            this.estante = ubicacion.estante ?? '';
            this.posicion = ubicacion.posicion ?? '';
            this.otras = resto.join(';');
        },

        cambiar(campo, paso) {
            const valor = (Number(this[campo]) || 0) + paso;
            this[campo] = Math.min(Math.max(valor, 1), LIMITES[campo]);
        },

        async guardar() {
            if (!this.elegido || this.guardando) {
                return;
            }
            this.guardando = true;
            this.error = '';
            try {
                const { data } = await window.axios.put(urls.guardar, { codprod: this.elegido.codprod, ubicacion: this.ubicacion });
                this.recientes = data.recientes;
                this.aviso = `${data.producto.desprod}: ${data.producto.sides}`;
                if (this.siguiente && this.posicion) {
                    this.cambiar('posicion', 1);
                }
                this.otras = '';
                this.recordar();
                this.otro();
            } catch (error) {
                this.error = mensajeDeError(error);
            } finally {
                this.guardando = false;
            }
        },

        async quitar() {
            if (!this.elegido || this.guardando || !confirm('¿Quitar la ubicación de SIDES? El producto vuelve a la del ERP.')) {
                return;
            }
            this.guardando = true;
            this.error = '';
            try {
                const { data } = await window.axios.delete(urls.quitar, { data: { codprod: this.elegido.codprod } });
                this.recientes = data.recientes;
                this.elegido = data.producto;
                this.aviso = data.mensaje;
            } catch (error) {
                this.error = mensajeDeError(error);
            } finally {
                this.guardando = false;
            }
        },
    };
}
