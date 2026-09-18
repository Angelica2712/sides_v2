/**
 * Monitor de pedidos en vivo.
 *
 * Reemplaza el refresco por tiempo (recargaba la página entera cada 60 segundos): ahora el
 * navegador escucha el canal privado sides-monitor.{codisb} y, apenas llega un aviso, vuelve a
 * pedir solo el contenido del monitor y lo cambia en su lugar. Así el pedido aparece cuando
 * llega, y no se pierden la vista elegida, el desplazamiento ni la pantalla completa.
 *
 * El aviso lo emiten los dos sistemas sobre el mismo canal (App\Events\MonitorActualizado en
 * SIDES, App\Events\MonitorSidesActualizado en seped_v2); el evento no trae datos, solo dice
 * "cambió algo": la base es compartida, así que el contenido se relee de ahí.
 *
 * Red de seguridad, no temporizador de refresco: mientras el socket esté caído (o si la
 * aplicación no tiene WebSocket configurado) se consulta una vez por minuto para que la
 * pantalla no quede congelada. Con el socket conectado no se consulta nada.
 */

const MS_SIN_CONEXION = 60000;
const MS_AGRUPAR_AVISOS = 250;

export default function monitorEnVivo({ canal, url, hora }) {
    return {
        vista: localStorage.getItem('sidesMonitorVista') || 'tablero',
        pantallaCompleta: false,
        conectado: false,
        // Distingue el primer enlace ("Conectando…") de haberse caído ("Reconectando…").
        hubo: false,
        actualizando: false,
        hora,
        agrupador: null,

        init() {
            this.$watch('vista', (valor) => localStorage.setItem('sidesMonitorVista', valor));

            document.addEventListener('fullscreenchange', () => {
                this.pantallaCompleta = !! document.fullscreenElement;
            });

            this.escuchar();

            setInterval(() => {
                if (! this.conectado) {
                    this.refrescar();
                }
            }, MS_SIN_CONEXION);
        },

        escuchar() {
            if (! window.Echo) {
                return;
            }

            window.Echo.private(canal)
                .listen('.actualizado', () => this.avisar())
                .subscribed(() => {
                    this.conectado = true;
                    this.hubo = true;
                    // Al (re)conectar se relee todo: mientras el socket estuvo caído pudo
                    // haber cambios que no llegaron.
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

        /** Varios cambios seguidos (terminar un lote, aprobar en tanda) piden una sola relectura. */
        avisar() {
            clearTimeout(this.agrupador);
            this.agrupador = setTimeout(() => this.refrescar(), MS_AGRUPAR_AVISOS);
        },

        async refrescar() {
            if (this.actualizando) {
                return;
            }

            this.actualizando = true;

            try {
                // La página actual del listado va en la URL; el fragmento tiene que respetarla.
                const respuesta = await fetch(url + window.location.search, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });

                // Sesión vencida: el fragmento sería la pantalla de login.
                if (respuesta.redirected || ! respuesta.ok) {
                    window.location.reload();

                    return;
                }

                document.getElementById('monitor-contenido').innerHTML = await respuesta.text();
                this.hora = new Date().toLocaleTimeString('es-VE', { hour12: false });
            } catch (error) {
                // Sin red: el monitor se queda con lo último que mostró y reintenta al reconectar.
            } finally {
                this.actualizando = false;
            }
        },

        alternarPantalla() {
            document.fullscreenElement ? document.exitFullscreen() : this.$root.requestFullscreen();
        },
    };
}
