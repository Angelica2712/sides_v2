/**
 * Ubicación de un producto. Por defecto en el formato que SIDES recomienda,
 * ZONA-PASILLO-ANAQUEL-ESTANTE-POSICION (PK-03-12-B-04), o en el que la droguería armó en
 * «Registrar ubicaciones» (por ejemplo PASILLO.RACK.NIVEL.HUECO = 03.12.2.04). Misma regla que
 * app/Support/UbicacionAlmacen.php: se puede cortar por la derecha y varias ubicaciones van
 * separadas con ";", la primera es la de picking.
 *
 * El formato de la droguería lo deja el layout en window.sidesFormatoUbicacion; usarFormato() lo
 * cambia en la página (el acomodo de otra droguería, o la vista previa del formato nuevo).
 *
 * Dónde queda físicamente cada número depende del acomodo del almacén de la droguería
 * (app/Support/ConfigAlmacen.php), que viene en plano.config.
 */
export const FORMATO_SIDES = {
    separador: '-',
    partes: [
        { rol: 'zona', nombre: 'Zona', tipo: 'letras', largo: 2 },
        { rol: 'pasillo', nombre: 'Pasillo', tipo: 'numeros', largo: 2 },
        { rol: 'anaquel', nombre: 'Anaquel', tipo: 'numeros', largo: 2 },
        { rol: 'estante', nombre: 'Estante', tipo: 'letras', largo: 1 },
        { rol: 'posicion', nombre: 'Posición', tipo: 'numeros', largo: 2 },
    ],
};

/** Zona de las ubicaciones de un formato sin zona. */
const ZONA_SIN_ZONA = 'PK';

let formatoPagina = null;
const patrones = new Map();

export function formatoActual() {
    return formatoPagina ?? window.sidesFormatoUbicacion ?? FORMATO_SIDES;
}

export function usarFormato(formato) {
    formatoPagina = formato ?? null;
}

const letra = (numero) => String.fromCharCode(64 + numero);
const relleno = (numero, largo) => String(numero).padStart(largo, '0');

function patron(formato) {
    const clave = JSON.stringify(formato);
    if (!patrones.has(clave)) {
        const separador = formato.separador.replace(/[.*+?^${}()|[\]\\/-]/g, '\\$&');
        const grupos = formato.partes.map((p) => `(?<${p.rol}>${p.tipo === 'letras' ? '[A-Z]' : '\\d'}{${p.largo}})`);
        const resto = grupos.slice(1).reduceRight((dentro, grupo) => `(?:${separador}${grupo}${dentro})?`, '');
        patrones.set(clave, new RegExp(`^${grupos[0]}${resto}$`));
    }
    return patrones.get(clave);
}

/** Ejemplo del formato: zona PK, pasillo 3, anaquel 12, estante 2 (B), posición 4. */
export function ejemplo(formato = formatoActual()) {
    return formatear({ zona: 'PK', pasillo: 3, anaquel: 12, estante: 'B', posicion: 4 }, formato);
}

/** Nombre del formato para los mensajes: ZONA-PASILLO-ANAQUEL-ESTANTE-POSICIÓN. */
export function nombreFormato(formato = formatoActual()) {
    return formato.partes.map((p) => p.nombre.toUpperCase()).join(formato.separador || ' ');
}

/** Nombre que la droguería le dio a una parte (rol), o null si su formato no la tiene. */
export function nombreParte(rol, formato = formatoActual()) {
    return formato.partes.find((p) => p.rol === rol)?.nombre ?? null;
}

/** Cómo se escribe el estante número indice (0 = el primero): letra o número según el formato. */
export function textoEstante(indice, formato = formatoActual()) {
    const parte = formato.partes.find((p) => p.rol === 'estante');
    return parte?.tipo === 'numeros' ? String(indice + 1) : letra(indice + 1);
}

/**
 * Escribe una ubicación (zona, números y estante como letra) en el formato; se corta en la
 * primera parte que falte.
 */
export function formatear(ubicacion, formato = formatoActual()) {
    const textos = [];
    for (const parte of formato.partes) {
        let valor = ubicacion[parte.rol];
        if (valor === null || valor === undefined || valor === '') {
            break;
        }
        if (parte.rol === 'estante') {
            valor = valor.charCodeAt(0) - 64;
        }
        textos.push(parte.rol === 'zona' ? valor : parte.tipo === 'letras' ? letra(valor) : relleno(valor, parte.largo));
    }
    return textos.join(formato.separador);
}

export const ZONAS = { PK: 'Picking', MY: 'Mayor', FR: 'Frío', PS: 'Psicotrópicos', DV: 'Devoluciones' };

export const CONFIG_POR_DEFECTO = {
    numeracion: 'alternada',
    estanteA: 'abajo',
    posicion1: 'izquierda',
    estantes: 4,
    posiciones: 4,
    zonas: {},
};

/**
 * El estante sale siempre como letra (A = el primero); textos trae cada parte como se escribió.
 *
 * @returns {{codigo: string, zona: string, pasillo: ?number, anaquel: ?number, estante: ?string, posicion: ?number, textos: Object<string, string>}|null}
 */
export function interpretar(texto, formato = formatoActual()) {
    const codigo = String(texto ?? '').trim().toUpperCase().replaceAll(' ', '');
    const grupos = codigo ? codigo.match(patron(formato))?.groups : null;
    if (!grupos) {
        return null;
    }
    const tipos = Object.fromEntries(formato.partes.map((p) => [p.rol, p.tipo]));
    const valor = (rol) => {
        const parte = grupos[rol];
        if (!parte) {
            return null;
        }
        return tipos[rol] === 'letras' ? parte.charCodeAt(0) - 64 : Number(parte);
    };
    const estante = valor('estante');
    if (estante !== null && (estante < 1 || estante > 26)) {
        return null;
    }

    return {
        codigo,
        zona: grupos.zona || ZONA_SIN_ZONA,
        pasillo: valor('pasillo'),
        anaquel: valor('anaquel'),
        estante: estante === null ? null : letra(estante),
        posicion: valor('posicion'),
        textos: Object.fromEntries(Object.entries(grupos).filter(([, parte]) => parte)),
    };
}

export function todas(texto, formato = formatoActual()) {
    return String(texto ?? '').split(';').map((parte) => interpretar(parte, formato)).filter(Boolean);
}

export function configDe(plano) {
    return { ...CONFIG_POR_DEFECTO, ...(plano?.config ?? {}) };
}

export function nombreZona(zona, config = CONFIG_POR_DEFECTO) {
    return config.zonas?.[zona] || ZONAS[zona] || zona;
}

/** Cuántos anaqueles caben uno tras otro a lo largo de un pasillo. */
export function columnasPasillo(maximo, numeracion) {
    return numeracion === 'izquierda' ? maximo : Math.ceil(maximo / 2);
}

/**
 * Lado del pasillo y columna (0 = el más cercano a la entrada) de un anaquel.
 * maximo es el número de anaquel más alto del pasillo (hace falta para la numeración en U).
 */
export function lugarAnaquel(numero, maximo, numeracion) {
    if (numeracion === 'izquierda') {
        return { izquierda: true, columna: numero - 1 };
    }
    if (numeracion === 'u') {
        const ida = Math.ceil(maximo / 2);
        return numero <= ida
            ? { izquierda: true, columna: numero - 1 }
            : { izquierda: false, columna: 2 * ida - numero };
    }
    return { izquierda: numero % 2 === 1, columna: Math.ceil(numero / 2) - 1 };
}

/** Número de anaquel más alto del pasillo de una ubicación, según el plano. */
export function maximoAnaquel(plano, ubicacion) {
    const pasillo = plano?.zonas
        ?.find((zona) => zona.zona === ubicacion.zona)
        ?.pasillos.find((p) => p.pasillo === ubicacion.pasillo);
    return Math.max(ubicacion.anaquel ?? 1, ...(pasillo?.anaqueles.map((a) => a.anaquel) ?? []));
}

/** Partes de una ubicación para mostrarlas por separado: [['Zona', 'Picking'], ['Pasillo', '3'], ...]. */
export function describir(ubicacion, plano = null) {
    if (!ubicacion) {
        return [];
    }
    const config = configDe(plano);
    const formato = formatoActual();
    const { zona, pasillo, anaquel, estante, posicion } = ubicacion;
    const lado = anaquel !== null && lugarAnaquel(anaquel, maximoAnaquel(plano, ubicacion), config.numeracion).izquierda;
    // Con los nombres y como lo escribe la droguería (el estante 2 o B, según su formato).
    const nombre = (rol) => nombreParte(rol, formato) ?? nombreParte(rol, FORMATO_SIDES);
    const texto = (rol, valor) => {
        const escrito = ubicacion.textos?.[rol];
        return escrito && /^\d+$/.test(escrito) ? String(Number(escrito)) : (escrito ?? String(valor));
    };

    return [
        nombreParte('zona', formato) && ['Zona', nombreZona(zona, config)],
        pasillo !== null && [nombre('pasillo'), texto('pasillo', pasillo)],
        anaquel !== null && [nombre('anaquel'), `${texto('anaquel', anaquel)} · ${lado ? 'izquierda' : 'derecha'}`],
        estante !== null && [nombre('estante'), `${texto('estante', estante)}${estante === 'A' ? ` · ${config.estanteA}` : ''}`],
        posicion !== null && [nombre('posicion'), `${texto('posicion', posicion)}${posicion === 1 ? ` · ${config.posicion1}` : ''}`],
    ].filter(Boolean);
}
