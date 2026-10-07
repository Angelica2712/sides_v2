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

const LETRA_MINIMA = 12;
const LETRA_MAXIMA = 40;

export default function monitorEnVivo({ canal, url, hora, letra, urlLetra }) {
    return {
        vista: localStorage.getItem('sidesMonitorVista') || 'tablero',
        // Letra de la vista Tabla: es de la droguería (Configuración) y la cambia desde acá
        // quien la administra; las demás pantallas la toman con el aviso del canal.
        letra,
        letraMinima: LETRA_MINIMA,
        letraMaxima: LETRA_MAXIMA,
        guardandoLetra: false,
        pantallaCompleta: false,
        conectado: false,
        // Distingue el primer enlace ("Conectando…") de haberse caído ("Reconectando…").
        hubo: false,
        actualizando: false,
        largo: false,
        alFinal: false,
        suelo: 20,
        hora,
        agrupador: null,

        init() {
            this.$watch('vista', (valor) => localStorage.setItem('sidesMonitorVista', valor));

            document.addEventListener('fullscreenchange', () => {
                this.pantallaCompleta = !! document.fullscreenElement;
                this.medir();
            });

            // Botón de brinco: con captura, porque en pantalla completa quien se desplaza es $root.
            window.addEventListener('scroll', () => this.medir(), { capture: true, passive: true });
            window.addEventListener('resize', () => this.medir(), { passive: true });
            this.$watch('vista', () => this.$nextTick(() => this.medir()));
            this.$nextTick(() => this.medir());

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
                // La letra pudo cambiarla otra pantalla: el número de los botones sigue a la tabla.
                this.letra = Number(document.querySelector('#monitor-contenido table[data-letra]')?.dataset.letra) || this.letra;
                this.hora = new Date().toLocaleTimeString('es-VE', { hour12: false });
                this.medir();
            } catch (error) {
                // Sin red: el monitor se queda con lo último que mostró y reintenta al reconectar.
            } finally {
                this.actualizando = false;
            }
        },

        /** Quien se desplaza: la página, o $root cuando el monitor está en pantalla completa. */
        contenedor() {
            return document.fullscreenElement ? this.$root : document.documentElement;
        },

        /** `largo`: hay bastante que bajar. `alFinal`: ya se ve el final. */
        medir() {
            const alto = window.innerHeight;
            const contenedor = this.contenedor();

            this.largo = contenedor.scrollHeight - contenedor.clientHeight > alto / 2;
            this.alFinal = this.$refs.fin.getBoundingClientRect().top <= alto + 80;

            // Con la paginación a la vista el botón se sube para no taparle las flechas.
            const paginas = document.querySelector('#monitor-contenido nav[role="navigation"]');
            const techo = paginas ? paginas.parentElement.getBoundingClientRect().top : alto;
            this.suelo = Math.max(20, alto - techo + 12);
        },

        brincar() {
            const contenedor = this.contenedor();

            contenedor.scrollTo({ top: this.alFinal ? 0 : contenedor.scrollHeight, behavior: 'smooth' });
        },

        async cambiarLetra(pasos) {
            const nueva = Math.max(LETRA_MINIMA, Math.min(LETRA_MAXIMA, this.letra + pasos));
            if (nueva === this.letra || this.guardandoLetra) {
                return;
            }

            this.guardandoLetra = true;

            try {
                const respuesta = await fetch(urlLetra, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ letra: nueva }),
                });

                if (respuesta.ok) {
                    this.letra = nueva;
                    await this.refrescar();
                }
            } catch (error) {
                // Sin red: la letra queda como estaba.
            } finally {
                this.guardandoLetra = false;
            }
        },

        alternarPantalla() {
            document.fullscreenElement ? document.exitFullscreen() : this.$root.requestFullscreen();
        },
    };
}
