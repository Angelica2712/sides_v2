/**
 * Picking de un lote de Batch Picking (resources/views/batch/picking.blade.php).
 *
 * Mismo flujo que el legacy de mastranto (admin/picking/alcabala/showLote): se escanea un
 * producto, si tiene varios lotes pendientes se elige cuál (sugerido el que vence primero),
 * se confirma si se recogió todo o se indica la cantidad, y el servidor la reparte entre los
 * pedidos por antigüedad.
 */
import { lector, sonido } from './lector';

export default function batchPicking({ productos, urls }) {
    return {
        ...lector(),
        productos,
        lectura: '',
        mensaje: '',
        tipoMensaje: 'error',
        opcionesLote: [],
        loteElegido: null,
        actual: null,
        cantidad: 0,
        errorCantidad: '',
        guardando: false,

        get pendientes() {
            return this.productos.filter((producto) => !producto.tocado);
        },
        get listo() {
            return this.pendientes.length === 0;
        },
        get ordenados() {
            // Pendientes arriba; dentro de cada grupo se respeta el orden del servidor.
            return [...this.pendientes, ...this.productos.filter((producto) => producto.tocado)];
        },
        get unidadesRequeridas() {
            return this.productos.reduce((total, producto) => total + producto.requerido, 0);
        },
        get unidadesPickeadas() {
            return this.productos.reduce((total, producto) => total + (producto.tocado ? producto.pickeado : 0), 0);
        },
        get progreso() {
            return this.productos.length ? Math.round(((this.productos.length - this.pendientes.length) / this.productos.length) * 100) : 100;
        },

        init() {
            this.iniciarLector();
        },

        normalizar(codigo) {
            return String(codigo ?? '').trim().replace(/^0+/, '');
        },

        leer() {
            const codigo = this.normalizar(this.lectura);
            this.lectura = '';
            if (!codigo) return;

            const coincidencias = this.productos.filter(
                (producto) => this.normalizar(producto.barra) === codigo || this.normalizar(producto.codprod) === codigo,
            );
            if (coincidencias.length === 0) {
                return this.avisar(`El código ${codigo} no está en este lote.`);
            }

            const pendientes = coincidencias.filter((producto) => !producto.tocado);
            const candidatos = (pendientes.length ? pendientes : coincidencias)
                .slice()
                .sort((a, b) => (a.vence || '9999').localeCompare(b.vence || '9999'));

            this.mensaje = '';
            sonido.exito();
            if (candidatos.length > 1) {
                this.opcionesLote = candidatos;
                this.loteElegido = candidatos[0].clave;
                this.$refs.elegirLote.showModal();
                return;
            }

            this.preguntar(candidatos[0]);
        },

        confirmarLote() {
            const producto = this.opcionesLote.find((opcion) => opcion.clave === this.loteElegido);
            this.$refs.elegirLote.close();
            if (producto) this.preguntar(producto);
        },

        preguntar(producto) {
            this.actual = producto;
            this.$refs.confirmar.showModal();
        },

        completo() {
            this.$refs.confirmar.close();
            this.guardar(this.actual, this.actual.requerido);
        },

        indicarCantidad(producto) {
            this.actual = producto;
            this.cantidad = producto.tocado ? producto.pickeado : producto.requerido;
            this.errorCantidad = '';
            if (this.$refs.confirmar.open) this.$refs.confirmar.close();
            this.$refs.editar.showModal();
            this.$nextTick(() => this.$refs.campoCantidad.select());
        },

        sumar(unidades) {
            this.cantidad = Math.min(Math.max((Number(this.cantidad) || 0) + unidades, 0), this.actual.requerido);
        },

        async guardarEdicion() {
            const cantidad = Number(this.cantidad);
            if (!Number.isInteger(cantidad) || cantidad < 0 || cantidad > this.actual.requerido) {
                this.errorCantidad = `La cantidad debe estar entre 0 y ${this.actual.requerido}.`;
                return;
            }
            this.$refs.editar.close();
            await this.guardar(this.actual, cantidad);
        },

        async guardar(producto, cantidad) {
            this.guardando = true;
            try {
                const { data } = await window.axios.post(urls.cantidad, { producto: producto.clave, cantidad });
                Object.assign(producto, data);
                this.avisar(`${producto.desprod}: ${data.pickeado} de ${data.requerido}.`, 'exito');
                if (this.listo) {
                    this.$nextTick(() => this.$refs.terminar.showModal());
                }
            } catch (error) {
                this.avisar(error.response?.data?.mensaje ?? 'No se pudo guardar. Revisa la conexión e inténtalo de nuevo.');
            } finally {
                this.guardando = false;
                this.$nextTick(() => this.$refs.lectura?.focus());
            }
        },

        avisar(texto, tipo = 'error') {
            this.mensaje = texto;
            this.tipoMensaje = tipo;
            tipo === 'exito' ? sonido.exito() : sonido.error();
        },
    };
}
