/**
 * Indicadores del almacén en el Inicio (resources/views/home.blade.php): tiempos promedio de
 * aceptar, picking y packing, la cola de ahora y el desempeño del período.
 *
 * Igual que el monitor escucha sides-monitor.{codisb}: cuando un pedido cambia de estado
 * («actualizado», MonitorActualizado) se vuelven a pedir los números. Los avisos de cada producto
 * («picking») no cambian ningún promedio y no se escuchan. Sin socket, o sin permiso para el canal,
 * se consulta cada minuto.
 */
const MS_SIN_CONEXION = 60000;
const MS_AGRUPAR_AVISOS = 1500;
const CLAVE_PERIODO = 'sides.inicio.periodo';

const leerPeriodo = (periodos) => {
    try {
        const guardado = window.localStorage.getItem(CLAVE_PERIODO);
        return guardado in periodos ? guardado : 'hoy';
    } catch {
        return 'hoy';
    }
};

/** 75 → «1 min 15 s», 5400 → «1 h 30 min». */
export const duracion = (segundos) => {
    if (segundos === null || segundos === undefined) {
        return '—';
    }
    if (segundos < 60) {
        return `${segundos} s`;
    }
    const minutos = Math.floor(segundos / 60);
    if (minutos < 60) {
        const resto = segundos % 60;
        return resto && minutos < 10 ? `${minutos} min ${resto} s` : `${minutos} min`;
    }
    const horas = Math.floor(minutos / 60);
    if (horas < 48) {
        const resto = minutos % 60;
        return resto ? `${horas} h ${resto} min` : `${horas} h`;
    }
    return `${Math.floor(horas / 24)} días`;
};

export default function inicioIndicadores({ canal, url, periodos }) {
    let agrupador = null;

    return {
        periodo: leerPeriodo(periodos),
        datos: null,
        cargando: true,
        conectado: false,
        duracion,

        init() {
            this.$watch('periodo', (valor) => {
                try {
                    window.localStorage.setItem(CLAVE_PERIODO, valor);
                } catch {
                    // Sin almacenamiento del navegador el período vuelve a «Hoy» al recargar.
                }
                this.refrescar();
            });
            this.refrescar();
            if (window.Echo) {
                window.Echo.private(canal)
                    .listen('.actualizado', () => {
                        clearTimeout(agrupador);
                        agrupador = setTimeout(() => this.refrescar(), MS_AGRUPAR_AVISOS);
                    })
                    .subscribed(() => {
                        this.conectado = true;
                    })
                    .error(() => {
                        this.conectado = false;
                    });
            }
            setInterval(() => {
                if (!this.conectado) {
                    this.refrescar();
                }
            }, MS_SIN_CONEXION);
        },

        async refrescar() {
            try {
                const { data } = await window.axios.get(url, { params: { periodo: this.periodo } });
                this.datos = data;
            } catch (error) {
                if (error.response?.status === 401 || error.response?.status === 419) {
                    window.location.reload();
                }
            } finally {
                this.cargando = false;
            }
        },

        tiempo(tramo) {
            return this.datos?.tiempos?.[tramo] ?? null;
        },

        /**
         * Comparado con el período anterior del mismo largo. En tiempos, menos es mejor.
         * @returns {{texto: string, mejor: boolean}|null}
         */
        cambio(tramo) {
            const t = this.tiempo(tramo);
            if (!t || t.segundos === null || !t.anterior) {
                return null;
            }
            const porcentaje = Math.round(((t.segundos - t.anterior) / t.anterior) * 100);
            if (porcentaje === 0) {
                return { texto: 'igual que antes', mejor: true };
            }
            return {
                texto: `${porcentaje > 0 ? '+' : ''}${porcentaje}% vs. período anterior`,
                mejor: porcentaje < 0,
            };
        },

        numero(valor) {
            return valor === null || valor === undefined ? '—' : Number(valor).toLocaleString('es-VE');
        },
    };
}
