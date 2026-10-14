/**
 * Pantalla de packing (resources/views/packing/show.blade.php).
 *
 * Cada lectura del código de barras verifica UNA unidad del primer renglón de ese producto
 * que todavía no está completo (chequeado < cantdesp), igual que el legacy. Cada lectura se
 * guarda en el servidor al momento; las lecturas se envían en cola para no perder ninguna
 * cuando se escanea rápido. Un código que no está en el pedido, o de un producto ya completo,
 * bloquea la pantalla hasta que un supervisor escribe su clave (legacy: bValidar).
 */
import { lector, sonido } from './lector';

export default function packingPedido({ renglones, urls, claveParaAjustar, separadorCestas }) {
    return {
        ...lector(),
        renglones,
        claveParaAjustar,
        lectura: '',
        unidades: 1,
        mensaje: '',
        tipoMensaje: 'error',
        ultimoItem: null,
        motivoBloqueo: '',
        clave: '',
        errorClave: '',
        enviando: false,
        ajuste: null,
        cantidadAjuste: 0,
        cola: Promise.resolve(),
        temporizadorCestas: null,

        get completos() {
            return this.renglones.filter((renglon) => ['completo', 'sin-despacho'].includes(this.estadoDe(renglon)));
        },
        get listo() {
            return this.completos.length === this.renglones.length;
        },
        get unidadesADespachar() {
            return this.renglones.reduce((total, renglon) => total + Math.max(renglon.cantdesp, 0), 0);
        },
        get unidadesVerificadas() {
            return this.renglones.reduce((total, renglon) => total + Math.min(renglon.chequeado, Math.max(renglon.cantdesp, 0)), 0);
        },
        get progreso() {
            return this.unidadesADespachar ? Math.round((this.unidadesVerificadas / this.unidadesADespachar) * 100) : 100;
        },
        get ordenados() {
            const peso = { parcial: 0, pendiente: 1, completo: 2, 'sin-despacho': 3 };
            return [...this.renglones].sort((a, b) => peso[this.estadoDe(a)] - peso[this.estadoDe(b)]);
        },

        init() {
            this.iniciarLector();
        },

        estadoDe(renglon) {
            if (renglon.cantdesp <= 0) return 'sin-despacho';
            if (renglon.chequeado >= renglon.cantdesp) return 'completo';
            return renglon.chequeado > 0 ? 'parcial' : 'pendiente';
        },

        normalizar(codigo) {
            // El legacy ignora los ceros a la izquierda al comparar códigos de barras.
            return String(codigo ?? '').trim().replace(/^0+/, '');
        },

        leer() {
            // "3*7591234" verifica 3 unidades; sin prefijo se usa el campo Unidades (por defecto 1).
            const lectura = String(this.lectura ?? '').trim();
            const conPrefijo = lectura.match(/^(\d+)\s*[*xX]\s*(\S+)$/);
            const unidades = conPrefijo ? Number(conPrefijo[1]) : Number(this.unidades) || 1;
            const codigo = this.normalizar(conPrefijo ? conPrefijo[2] : lectura);

            this.lectura = '';
            this.unidades = 1;
            if (!codigo) return;

            if (!Number.isInteger(unidades) || unidades < 1) {
                return this.avisar('Las unidades deben ser un número mayor que cero.');
            }

            const coincidencias = this.renglones.filter((renglon) => this.normalizar(renglon.barra) === codigo);
            if (coincidencias.length === 0) {
                return this.bloquear(`El código ${codigo} no está en este pedido`);
            }

            const renglon = coincidencias.find((r) => r.cantdesp > 0 && r.chequeado < r.cantdesp);
            if (!renglon) {
                return this.bloquear(`${coincidencias[0].desprod} ya está completo`);
            }

            const faltan = renglon.cantdesp - renglon.chequeado;
            if (unidades > faltan) {
                return this.avisar(`${renglon.desprod}: solo ${faltan === 1 ? 'falta 1 unidad' : `faltan ${faltan} unidades`}, no ${unidades}.`);
            }

            // Se refleja al instante y se confirma con el servidor en orden.
            renglon.chequeado += unidades;
            this.ultimoItem = renglon.item;
            this.avisar(`${renglon.desprod}: ${renglon.chequeado} de ${renglon.cantdesp}`, 'exito');

            this.cola = this.cola.then(async () => {
                try {
                    const { data } = await window.axios.post(urls.escanear, { item: renglon.item, unidades });
                    renglon.chequeado = data.chequeado;
                } catch (error) {
                    renglon.chequeado = Math.max(renglon.chequeado - unidades, 0);
                    this.avisar(error.response?.data?.mensaje ?? 'No se pudo guardar la lectura. Vuelve a escanear el producto.');
                }
            });
        },

        bloquear(motivo) {
            sonido.error();
            this.motivoBloqueo = motivo;
            this.clave = '';
            this.errorClave = '';
            this.$refs.bloqueo.showModal();
            this.$nextTick(() => this.$refs.campoBloqueo.focus());
        },

        async desbloquear() {
            this.enviando = true;
            this.errorClave = '';
            try {
                await window.axios.post(urls.clave, { clave: this.clave });
                this.$refs.bloqueo.close();
                this.clave = '';
                this.$nextTick(() => this.$refs.lectura?.focus());
            } catch (error) {
                this.errorClave = error.response?.data?.mensaje ?? 'No se pudo validar la clave.';
            } finally {
                this.enviando = false;
            }
        },

        abrirAjuste(renglon) {
            this.ajuste = renglon;
            this.cantidadAjuste = Math.max(renglon.cantdesp, 0);
            this.clave = '';
            this.errorClave = '';
            this.$refs.ajuste.showModal();
            this.$nextTick(() => this.$refs.campoAjuste.select());
        },

        async guardarAjuste() {
            const renglon = this.ajuste;
            const cantidad = Number(this.cantidadAjuste);
            if (!Number.isInteger(cantidad) || cantidad < 0 || cantidad > renglon.cantidad) {
                this.errorClave = `La cantidad debe estar entre 0 y ${renglon.cantidad}.`;
                return;
            }

            this.enviando = true;
            this.errorClave = '';
            try {
                const { data } = await window.axios.post(urls.ajustar, { item: renglon.item, cantidad, clave: this.clave || null });
                renglon.cantdesp = data.cantdesp;
                renglon.chequeado = data.cantdesp;
                this.ultimoItem = renglon.item;
                this.$refs.ajuste.close();
                this.avisar(`${renglon.desprod}: ajustado a ${data.cantdesp}.`, 'exito');
                this.$nextTick(() => this.$refs.lectura?.focus());
            } catch (error) {
                this.errorClave = error.response?.data?.mensaje ?? 'No se pudo guardar el ajuste.';
            } finally {
                this.enviando = false;
            }
        },

        async cambiarLote(renglon, valor) {
            try {
                const { data } = await window.axios.post(urls.lote, { item: renglon.item, lote: valor });
                renglon.lote = data.lote;
                renglon.vence = data.vence;
                this.avisar(`${renglon.desprod}: lote cambiado a ${data.lote}.`, 'exito');
            } catch (error) {
                this.avisar(error.response?.data?.mensaje ?? 'No se pudo cambiar el lote.');
            }
        },

        abrirTerminar() {
            this.$refs.terminar.showModal();
        },

        separarCestas(evento) {
            // sides_cfg.activar_separador_automatico: tras 1 s sin escribir agrega ", " para la siguiente cesta.
            if (!separadorCestas) return;
            clearTimeout(this.temporizadorCestas);
            this.temporizadorCestas = setTimeout(() => {
                if (evento.target.value && !evento.target.value.endsWith(', ')) {
                    evento.target.value += ', ';
                }
            }, 1000);
        },

        avisar(texto, tipo = 'error') {
            this.mensaje = texto;
            this.tipoMensaje = tipo;
            tipo === 'exito' ? sonido.exito() : sonido.error();
        },
    };
}
