/**
 * Ubicación en el formato que SIDES recomienda: ZONA-PASILLO-ANAQUEL-ESTANTE-POSICION (PK-03-12-B-04).
 * Misma regla que app/Support/UbicacionAlmacen.php: se puede cortar por la derecha y varias
 * ubicaciones van separadas con ";", la primera es la de picking.
 *
 * Dónde queda físicamente cada número depende del acomodo del almacén de la droguería
 * (app/Support/ConfigAlmacen.php), que viene en plano.config.
 */
const PATRON = /^([A-Z]{2})(?:-(\d{2})(?:-(\d{2})(?:-([A-Z])(?:-(\d{2}))?)?)?)?$/;

export const ZONAS = { PK: 'Picking', MY: 'Mayor', FR: 'Frío', PS: 'Psicotrópicos', DV: 'Devoluciones' };

export const CONFIG_POR_DEFECTO = {
    numeracion: 'alternada',
    estanteA: 'abajo',
    posicion1: 'izquierda',
    estantes: 4,
    posiciones: 4,
    zonas: {},
};

/** @returns {{codigo: string, zona: string, pasillo: ?number, anaquel: ?number, estante: ?string, posicion: ?number}|null} */
export function interpretar(texto) {
    const codigo = String(texto ?? '').trim().toUpperCase().replaceAll(' ', '');
    const partes = codigo.match(PATRON);
    if (!partes) {
        return null;
    }
    const numero = (valor) => (valor ? Number(valor) : null);

    return {
        codigo,
        zona: partes[1],
        pasillo: numero(partes[2]),
        anaquel: numero(partes[3]),
        estante: partes[4] ?? null,
        posicion: numero(partes[5]),
    };
}

export function todas(texto) {
    return String(texto ?? '').split(';').map(interpretar).filter(Boolean);
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
    const { zona, pasillo, anaquel, estante, posicion } = ubicacion;
    const lado = anaquel !== null && lugarAnaquel(anaquel, maximoAnaquel(plano, ubicacion), config.numeracion).izquierda;

    return [
        ['Zona', nombreZona(zona, config)],
        pasillo !== null && ['Pasillo', String(pasillo)],
        anaquel !== null && ['Anaquel', `${anaquel} · ${lado ? 'izquierda' : 'derecha'}`],
        estante !== null && ['Estante', `${estante}${estante === 'A' ? ` · ${config.estanteA}` : ''}`],
        posicion !== null && ['Posición', `${posicion}${posicion === 1 ? ` · ${config.posicion1}` : ''}`],
    ].filter(Boolean);
}
