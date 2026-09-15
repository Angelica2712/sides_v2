/**
 * Lector de códigos de barras compartido por Picking, Packing y Batch Picking.
 *
 * Los escáneres USB/Bluetooth escriben el código como un teclado muy rápido y terminan con Enter
 * (o Tab). Con el cursor en el campo de lectura el formulario lo recibe normal; si el operario tocó
 * otra parte de la pantalla, escucharEscaner reconoce la ráfaga de teclas y entrega la lectura igual.
 */
const MAX_MS_ENTRE_TECLAS = 50;
const MIN_CARACTERES = 3;
const CLAVE_SONIDO = 'sidesSonidoLector';

let audio = null;

function tono(frecuencia, duracion, retraso = 0) {
    try {
        audio ??= new (window.AudioContext || window.webkitAudioContext)();
        if (audio.state === 'suspended') audio.resume();

        const inicio = audio.currentTime + retraso;
        const oscilador = audio.createOscillator();
        const volumen = audio.createGain();
        oscilador.type = 'square';
        oscilador.frequency.value = frecuencia;
        volumen.gain.setValueAtTime(0.0001, inicio);
        volumen.gain.exponentialRampToValueAtTime(0.2, inicio + 0.01);
        volumen.gain.exponentialRampToValueAtTime(0.0001, inicio + duracion);
        oscilador.connect(volumen).connect(audio.destination);
        oscilador.start(inicio);
        oscilador.stop(inicio + duracion + 0.02);
    } catch {
        // Sin Web Audio el lector sigue funcionando, solo sin sonido.
    }
}

function preferenciaSonido() {
    try {
        return localStorage.getItem(CLAVE_SONIDO) !== 'off';
    } catch {
        return true;
    }
}

export const sonido = {
    activo: preferenciaSonido(),

    alternar() {
        this.activo = !this.activo;
        try {
            localStorage.setItem(CLAVE_SONIDO, this.activo ? 'on' : 'off');
        } catch {
            // La preferencia dura solo mientras la página esté abierta.
        }
        this.exito();
        return this.activo;
    },

    exito() {
        if (this.activo) tono(1500, 0.08);
    },

    error() {
        navigator.vibrate?.([120, 60, 120]);
        if (this.activo) {
            tono(330, 0.16);
            tono(250, 0.24, 0.2);
        }
    },
};

function esEditable(elemento) {
    return elemento instanceof HTMLElement
        && (elemento.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(elemento.tagName));
}

/** Llama alLeer(codigo) cuando llega un escaneo con el cursor fuera de un campo. Devuelve la función para dejar de escuchar. */
export function escucharEscaner(alLeer) {
    let lectura = '';
    let ultimaTecla = 0;

    const alPresionar = (evento) => {
        if (evento.ctrlKey || evento.altKey || evento.metaKey) return;
        if (esEditable(evento.target)) {
            lectura = '';
            return;
        }

        const ahora = performance.now();
        const rapida = ahora - ultimaTecla <= MAX_MS_ENTRE_TECLAS;

        if (evento.key === 'Enter' || evento.key === 'Tab') {
            if (rapida && lectura.length >= MIN_CARACTERES) {
                // Sin esto el Enter del escáner "presionaría" el botón que tenga el foco.
                evento.preventDefault();
                evento.stopPropagation();
                if (document.querySelector('dialog[open]')) {
                    sonido.error();
                } else {
                    alLeer(lectura);
                }
            }
            lectura = '';
            return;
        }

        if (evento.key.length !== 1) return;
        if (!rapida) lectura = '';
        lectura += evento.key;
        ultimaTecla = ahora;
    };

    document.addEventListener('keydown', alPresionar, true);
    return () => document.removeEventListener('keydown', alPresionar, true);
}

/**
 * Estado y acciones del lector para mezclar en un componente Alpine que tenga `lectura`,
 * `leer()` y un x-ref="lectura" (ver resources/views/components/lector-opciones.blade.php).
 */
export function lector() {
    return {
        teclado: false,
        conSonido: sonido.activo,
        soltarEscaner: null,

        iniciarLector() {
            this.soltarEscaner = escucharEscaner((codigo) => {
                this.lectura = codigo;
                this.leer();
                this.$nextTick(() => this.$refs.lectura?.focus());
            });
            this.$nextTick(() => this.$refs.lectura?.focus());
        },

        destroy() {
            this.soltarEscaner?.();
        },

        alternarTeclado() {
            this.teclado = !this.teclado;
            const campo = this.$refs.lectura;
            campo?.blur();
            this.$nextTick(() => campo?.focus());
        },

        alternarSonido() {
            this.conSonido = sonido.alternar();
            this.$refs.lectura?.focus();
        },
    };
}
