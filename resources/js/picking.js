/**
 * Pantalla de picking (resources/views/picking/show.blade.php).
 *
 * Regla del legacy (sides_droactiva admin/picking/show.blade.php): se escanea el código de barras
 * y solo se acepta el SIGUIENTE producto pendiente, en el orden de recolección que manda el
 * servidor (sides_cfg.ordenPedSides). Un renglón está pendiente mientras cantdesp = -1.
 *
 * Con sides_cfg.pickingOrdenLibre se acepta cualquier producto pendiente: el escaneado (o el que
 * se toque en la lista de siguientes) pasa a ser el producto en curso. Sirve para recoger de una
 * vez todos los de una misma marca aunque en la lista queden separados.
 */
import { crearVisor } from './almacen-3d';
import { lector, sonido } from './lector';
import { describir, todas } from './ubicacion';

export default function pickingPedido({ renglones, urls, requiereClave, ordenLibre = false }) {
    // Visor 3D del almacén (fuera del estado de Alpine: three.js no soporta sus proxies).
    let visor = null;

    return {
        ...lector(),
        renglones,
        ordenLibre,
        lectura: '',
        // Producto elegido fuera de orden (solo con ordenLibre); null = el primero pendiente.
        elegido: null,
        seleccionado: null,
        cantidad: 0,
        manual: false,
        clave: '',
        mensaje: '',
        tipoMensaje: 'error',
        guardando: false,
        mensajeAlmacen: '',
        planoAlmacen: null,

        get pendientes() {
            return this.renglones.filter((renglon) => renglon.cantdesp < 0);
        },
        get revisados() {
            return this.renglones.filter((renglon) => renglon.cantdesp >= 0);
        },
        get actual() {
            return this.pendientes.find((renglon) => renglon.item === this.elegido) ?? this.pendientes[0] ?? null;
        },
        get siguientes() {
            return this.pendientes.filter((renglon) => renglon.item !== this.actual?.item);
        },
        get unidadesSolicitadas() {
            return this.renglones.reduce((total, renglon) => total + renglon.cantidad, 0);
        },
        get unidadesDespachadas() {
            return this.revisados.reduce((total, renglon) => total + renglon.cantdesp, 0);
        },
        get unidadesFaltantes() {
            return this.pendientes.reduce((total, renglon) => total + renglon.cantidad, 0);
        },
        get ubicacionAlmacen() {
            return todas(this.actual?.ubicacion)[0] ?? null;
        },
        get detalleAlmacen() {
            return describir(this.ubicacionAlmacen, this.planoAlmacen);
        },
        get progreso() {
            return this.renglones.length ? Math.round((this.revisados.length / this.renglones.length) * 100) : 0;
        },

        init() {
            this.iniciarLector();
        },

        normalizar(codigo) {
            // El legacy ignora los ceros a la izquierda al comparar códigos de barras.
            return String(codigo ?? '').trim().replace(/^0+/, '');
        },

        leer() {
            const codigo = this.normalizar(this.lectura);
            this.lectura = '';
            if (!codigo || !this.actual) {
                return;
            }
            if (this.seleccionado) {
                return this.avisar(`Primero confirma la cantidad de ${this.actual.desprod}.`);
            }

            const coincidencias = this.renglones.filter((renglon) => this.normalizar(renglon.barra) === codigo);
            if (coincidencias.length === 0) {
                return this.avisar(`El código ${codigo} no está en este pedido.`);
            }

            const pendiente = coincidencias.find((renglon) => renglon.cantdesp < 0);
            if (!pendiente) {
                return this.avisar('Ese producto ya fue revisado.');
            }

            if (pendiente.item !== this.actual.item) {
                if (!this.ordenLibre) {
                    return this.avisar(`Ese no es el siguiente producto. Busca primero ${this.actual.desprod} en ${this.actual.ubicacion}.`);
                }
                this.elegido = pendiente.item;
            }

            sonido.exito();
            this.empezarConfirmacion(false);
        },

        /** Con orden libre, tocar un producto de "Siguientes" lo trae como producto en curso. */
        elegir(renglon) {
            if (!this.ordenLibre || this.seleccionado) {
                return;
            }
            this.elegido = renglon.item;
            this.mensaje = '';
            window.scrollTo({ top: 0, behavior: 'smooth' });
            this.$nextTick(() => this.$refs.lectura?.focus());
        },

        seleccionar(manual) {
            if (manual && requiereClave) {
                this.clave = '';
                this.$refs.clave.showModal();
                this.$nextTick(() => this.$refs.campoClave.focus());
                return;
            }
            this.empezarConfirmacion(manual);
        },

        confirmarClave() {
            this.$refs.clave.close();
            this.empezarConfirmacion(true);
        },

        empezarConfirmacion(manual) {
            this.manual = manual;
            this.seleccionado = this.actual.item;
            this.cantidad = this.actual.cantidad;
            this.mensaje = '';
            this.$nextTick(() => this.$refs.confirmar?.focus());
        },

        sumar(unidades) {
            this.cantidad = Math.min(Math.max((Number(this.cantidad) || 0) + unidades, 0), this.actual.cantidad);
        },

        async guardar() {
            const renglon = this.renglones.find((r) => r.item === this.seleccionado);
            if (!renglon || this.guardando) {
                return;
            }

            const cantidad = Number(this.cantidad);
            if (!Number.isInteger(cantidad) || cantidad < 0 || cantidad > renglon.cantidad) {
                return this.avisar(`La cantidad debe estar entre 0 y ${renglon.cantidad}.`);
            }

            this.guardando = true;
            try {
                const { data } = await window.axios.post(urls.cantidad, {
                    item: renglon.item,
                    cantidad,
                    manual: this.manual,
                    clave: this.clave || null,
                });
                renglon.cantdesp = data.cantdesp;
                this.avisar(`${renglon.desprod}: ${data.cantdesp} de ${renglon.cantidad}.`, 'exito');
                this.cancelar();
                // Guardado el elegido, la pantalla vuelve al primero pendiente de la lista.
                this.elegido = null;
            } catch (error) {
                this.avisar(error.response?.data?.mensaje ?? 'No se pudo guardar. Revisa la conexión e inténtalo de nuevo.');
            } finally {
                this.guardando = false;
                this.$nextTick(() => this.$refs.lectura?.focus());
            }
        },

        cancelar() {
            this.seleccionado = null;
            this.cantidad = 0;
            this.manual = false;
            this.clave = '';
        },

        async alternarAlerta(renglon) {
            const anterior = renglon.alertalote;
            renglon.alertalote = anterior ? 0 : 1;
            try {
                const { data } = await window.axios.post(urls.alerta, { item: renglon.item });
                renglon.alertalote = data.alertalote;
            } catch (error) {
                renglon.alertalote = anterior;
                this.avisar(error.response?.data?.mensaje ?? 'No se pudo cambiar la alerta de lote.');
            }
        },

        /** El botón del almacén sale si el módulo está activo y la ubicación sigue el formato. */
        enAlmacen(renglon) {
            return Boolean(urls.plano) && todas(renglon?.ubicacion).length > 0;
        },

        async abrirAlmacen() {
            this.mensajeAlmacen = '';
            this.$refs.almacen.showModal();
            try {
                if (!visor) {
                    const { data } = await window.axios.get(urls.plano);
                    this.planoAlmacen = data;
                    visor = await crearVisor(this.$refs.visorAlmacen, data);
                }
            } catch (error) {
                this.mensajeAlmacen = 'No se pudo cargar el almacén. Revisa la conexión.';
                return;
            }
            const siguientes = this.siguientes.map((renglon) => todas(renglon.ubicacion)[0]).filter(Boolean);
            if (!visor.resaltar(this.ubicacionAlmacen, siguientes)) {
                this.mensajeAlmacen = `La ubicación ${this.ubicacionAlmacen.codigo} no está en el plano del almacén.`;
            }
        },

        avisar(texto, tipo = 'error') {
            this.mensaje = texto;
            this.tipoMensaje = tipo;
            tipo === 'exito' ? sonido.exito() : sonido.error();
        },
    };
}
