/**
 * Registrar ubicaciones (resources/views/almacen/ubicaciones.blade.php): desde el teléfono se busca
 * o escanea un producto y se le arma la ubicación parte por parte, en el formato de la droguería
 * (por defecto el de SIDES: zona, pasillo, anaquel, estante, posición). Lo armado se conserva
 * entre un producto y otro, y la posición avanza sola, para ir recorriendo el anaquel sin volver
 * a escribir todo.
 *
 * Quien administra la droguería arma aquí también su formato (urls.formato): qué partes lleva, en
 * qué orden, cómo se llaman, cuántos dígitos o letras tiene cada una y el separador.
 */
import { describir, ejemplo, interpretar, nombreFormato } from './ubicacion';

const CLAVE_ARMADO = 'sidesUbicacionArmada';
const NOMBRES_TIPO = { numeros: 'Números', letras: 'Letras' };

const copia = (valor) => JSON.parse(JSON.stringify(valor));
const letras = (cantidad) => Array.from({ length: cantidad }, (_, i) => String.fromCharCode(65 + i));

function mensajeDeError(error) {
    const datos = error.response?.data;
    return Object.values(datos?.errors ?? {})[0]?.[0] ?? datos?.message ?? 'No se pudo guardar. Revisa la conexión e intenta de nuevo.';
}

export default function registroUbicaciones({ plano, zonas, recientes, buscar, formato, formatoSides, reglas, roles, separadores, urls }) {
    let espera = null;
    const estantes = Math.min(Math.max(plano.config?.estantes ?? 4, 4), 26);
    // Lo armado se recuerda por formato: si el formato cambia, se empieza de nuevo.
    const huella = JSON.stringify(formato);

    /** Valor con que empieza cada parte: la primera zona, 1, o la letra A. */
    const inicial = (parte) => {
        if (parte.rol === 'zona') {
            return 'PK' in zonas ? 'PK' : (Object.keys(zonas)[0] ?? 'PK');
        }
        return parte.tipo === 'letras' ? 'A' : 1;
    };

    return {
        zonas,
        recientes,
        formato,
        roles,
        separadores,
        nombresTipo: NOMBRES_TIPO,
        editaFormato: Boolean(urls.formato),

        buscar: buscar ?? '',
        productos: [],
        buscando: false,
        elegido: null,

        valores: Object.fromEntries(formato.partes.map((parte) => [parte.rol, inicial(parte)])),
        otras: '',
        siguiente: true,

        guardando: false,
        error: '',
        aviso: '',

        // Editor del formato (null = cerrado).
        borrador: null,
        errorFormato: '',

        get nombreFormato() {
            return nombreFormato(this.formato);
        },
        get ejemplo() {
            return ejemplo(this.formato);
        },
        /** Parte que avanza sola al guardar: la posición o, si el formato no la tiene, la última de números. */
        get avanza() {
            return this.formato.partes.find((p) => p.rol === 'posicion') ?? this.formato.partes.findLast((p) => p.tipo === 'numeros');
        },

        /** Código armado; se corta en la primera parte vacía (el formato admite cortar por la derecha). */
        get codigo() {
            const textos = [];
            for (const parte of this.formato.partes) {
                const valor = this.valores[parte.rol];
                if (valor === '' || valor === null || valor === undefined) {
                    break;
                }
                textos.push(parte.tipo === 'numeros' ? String(valor).padStart(parte.largo, '0') : String(valor).toUpperCase());
            }
            return textos.join(this.formato.separador);
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
                if (armado?.formato === huella) {
                    Object.assign(this.valores, armado.valores);
                    this.siguiente = armado.siguiente ?? this.siguiente;
                }
            } catch {
                // Sin almacenamiento del navegador se empieza desde el principio.
            }
            if (this.buscar) {
                this.enviar();
            }
            this.$nextTick(() => this.$refs.buscar?.focus());
        },

        recordar() {
            try {
                localStorage.setItem(CLAVE_ARMADO, JSON.stringify({ formato: huella, valores: this.valores, siguiente: this.siguiente }));
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

        /** Se ve una parte si la anterior tiene valor: la ubicación se corta en la primera vacía. */
        visible(indice) {
            const anterior = this.formato.partes[indice - 1];
            return !anterior || !['', null, undefined].includes(this.valores[anterior.rol]);
        },

        /** Letras para elegir: los estantes del almacén, o todo el abecedario. */
        letrasDe(parte) {
            return letras(parte.rol === 'estante' ? estantes : 26);
        },

        maximo(parte) {
            return 10 ** parte.largo - 1;
        },

        /** Deja la ubicación hasta antes de esta parte («Sin estante»). */
        cortar(indice) {
            for (const parte of this.formato.partes.slice(indice)) {
                this.valores[parte.rol] = '';
            }
        },

        /** La ubicación (la primera, si son varias) está en el formato de la droguería. */
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
            for (const parte of this.formato.partes) {
                const valor = ubicacion.textos[parte.rol] ?? '';
                this.valores[parte.rol] = valor && parte.tipo === 'numeros' ? Number(valor) : valor;
            }
            this.otras = resto.join(';');
        },

        cambiar(parte, paso) {
            const valor = (Number(this.valores[parte.rol]) || 0) + paso;
            this.valores[parte.rol] = Math.min(Math.max(valor, 1), this.maximo(parte));
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
                const avanza = this.avanza;
                if (this.siguiente && avanza && this.valores[avanza.rol] !== '') {
                    this.cambiar(avanza, 1);
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

        // ---- Formato de la droguería ----

        get ejemploBorrador() {
            return this.borrador ? ejemplo(this.borrador) : '';
        },
        get nombreBorrador() {
            return this.borrador ? nombreFormato(this.borrador) : '';
        },
        get rolesLibres() {
            const usados = this.borrador?.partes.map((p) => p.rol) ?? [];
            return Object.keys(roles).filter((rol) => !usados.includes(rol));
        },

        abrirFormato() {
            this.borrador = copia(this.formato);
            this.errorFormato = '';
        },

        tiposDe(rol) {
            return Object.keys(reglas[rol] ?? {});
        },

        largosDe(parte) {
            return reglas[parte.rol]?.[parte.tipo] ?? [];
        },

        /** Al cambiar el papel o el tipo de una parte, el tipo y el largo pasan a uno que ese papel admite. */
        ajustar(parte, cambioRol = false) {
            if (cambioRol) {
                parte.nombre = roles[parte.rol];
            }
            if (!this.tiposDe(parte.rol).includes(parte.tipo)) {
                parte.tipo = this.tiposDe(parte.rol)[0];
            }
            if (!this.largosDe(parte).includes(Number(parte.largo))) {
                parte.largo = this.largosDe(parte).at(-1);
            }
            parte.largo = Number(parte.largo);
        },

        agregarParte() {
            const rol = this.rolesLibres[0];
            if (!rol) {
                return;
            }
            const parte = { rol, nombre: roles[rol], tipo: '', largo: 0 };
            this.ajustar(parte);
            this.borrador.partes.push(parte);
        },

        quitarParte(indice) {
            this.borrador.partes.splice(indice, 1);
        },

        moverParte(indice, paso) {
            const destino = indice + paso;
            if (destino < 0 || destino >= this.borrador.partes.length) {
                return;
            }
            const [parte] = this.borrador.partes.splice(indice, 1);
            this.borrador.partes.splice(destino, 0, parte);
        },

        formatoSides() {
            this.borrador = copia(formatoSides);
            this.errorFormato = '';
        },

        async guardarFormato() {
            if (!this.borrador || this.guardando) {
                return;
            }
            if (!confirm(`¿Usar el formato ${this.nombreBorrador} (por ejemplo ${this.ejemploBorrador}) en toda la droguería? Las ubicaciones escritas en otro formato dejan de verse en el Almacén 3D.`)) {
                return;
            }
            this.guardando = true;
            this.errorFormato = '';
            try {
                await window.axios.put(urls.formato, this.borrador);
                // El formato lo usan el 3D, el picking y esta pantalla: se recarga todo con el nuevo.
                window.location.reload();
            } catch (error) {
                this.errorFormato = mensajeDeError(error);
                this.guardando = false;
            }
        },
    };
}
