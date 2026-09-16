/**
 * Barra de desplazamiento horizontal arriba de una tabla ancha
 * (resources/views/components/tabla-desplazable.blade.php). Copia el ancho de la tabla y mueve
 * las dos barras juntas, así se ven las columnas de la derecha sin bajar hasta el final.
 */
export default function tablaDesplazable() {
    return {
        ancho: 0,
        visible: false,
        observador: null,

        init() {
            const actualizar = () => {
                const caja = this.$refs.caja;
                this.ancho = caja.scrollWidth;
                this.visible = caja.scrollWidth > caja.clientWidth + 1;
            };

            actualizar();
            this.observador = new ResizeObserver(actualizar);
            this.observador.observe(this.$refs.caja);
            if (this.$refs.caja.firstElementChild) {
                this.observador.observe(this.$refs.caja.firstElementChild);
            }
        },

        destroy() {
            this.observador?.disconnect();
        },

        // Solo se asigna si cambió: así una barra no vuelve a mover a la otra en bucle.
        desdeBarra() {
            if (this.$refs.caja.scrollLeft !== this.$refs.barra.scrollLeft) {
                this.$refs.caja.scrollLeft = this.$refs.barra.scrollLeft;
            }
        },

        desdeCaja() {
            if (this.$refs.barra.scrollLeft !== this.$refs.caja.scrollLeft) {
                this.$refs.barra.scrollLeft = this.$refs.caja.scrollLeft;
            }
        },
    };
}
