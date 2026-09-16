/**
 * Carga y descarga de guías: cada lectura del escáner envía el formulario y la página vuelve con
 * el resultado. El sonido de éxito o error se da al volver, según el mensaje de la respuesta.
 */
import { lector, sonido } from './lector';

export default (resultado = null) => ({
    ...lector(),
    lectura: '',

    init() {
        if (resultado === 'exito') sonido.exito();
        if (resultado === 'error') sonido.error();
        this.iniciarLector();
    },

    leer() {
        const codigo = this.lectura.trim();
        if (codigo === '') return;
        this.lectura = codigo;
        this.$nextTick(() => this.$refs.formulario.requestSubmit());
    },
});
