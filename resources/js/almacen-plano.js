/**
 * Geometría del almacén, sin three.js: dónde queda cada anaquel en el plano (en metros, visto
 * desde arriba) y qué caja ocupa cada ubicación. La usan el visor 3D (./almacen-3d.js) y el
 * editor del plano de Administración (./almacen-config.js).
 *
 * Disposición automática: cada zona va al lado de la anterior; dentro de la zona los pasillos
 * corren a lo largo, uno detrás de otro, con la entrada en el extremo izquierdo, y los anaqueles se
 * reparten a cada lado según la numeración de la droguería (plano.config). Los anaqueles que el
 * administrador movió a mano (config.disposicion) quedan donde los puso, con su giro.
 *
 * Ejes: x a la derecha, z hacia abajo en el plano, y hacia arriba. Un anaquel con giro 0 tiene el
 * frente (el lado del que se recoge) mirando hacia +z.
 */
import { columnasPasillo, configDe, lugarAnaquel } from './ubicacion';

export const POSICION_ANCHO = 0.45;
export const ESTANTE_ALTO = 0.55;
export const FONDO = 0.6;
export const PASILLO_ANCHO = 1.8;
const SEPARACION = 0.08;
const ENTRE_PASILLOS = PASILLO_ANCHO + 2 * FONDO + 0.5;
const ENTRE_ZONAS = 3;
const ZONA_SOLA = 3;

const dosDigitos = (numero) => String(numero).padStart(2, '0');

/** Clave de un anaquel en config.disposicion: "PK-03-12". */
export const claveAnaquel = (zona, pasillo, anaquel) => `${zona}-${dosDigitos(pasillo)}-${dosDigitos(anaquel)}`;

/** Números de los anaqueles de un pasillo registrado: impares a la izquierda, pares a la derecha. */
export function anaquelesDe({ izquierda = 0, derecha = 0 }) {
    return [
        ...Array.from({ length: izquierda }, (_, i) => 2 * i + 1),
        ...Array.from({ length: derecha }, (_, i) => 2 * i + 2),
    ];
}

/**
 * Plano armado con la configuración que se está editando, igual que lo arma
 * app/Services/Almacen/PlanoAlmacen.php: lo que sale de las ubicaciones de los productos
 * (`productos`, que manda el servidor), los pasillos registrados sin sus anaqueles borrados y los
 * anaqueles sueltos numerados a mano (config.extras). Así el editor dibuja cada cambio al momento.
 */
export function armarPlano(productos, config) {
    const zonas = new Map();
    const pasilloDe = (zona, pasillo) => {
        if (!zonas.has(zona)) zonas.set(zona, new Map());
        if (!zonas.get(zona).has(pasillo)) zonas.get(zona).set(pasillo, new Map());
        return zonas.get(zona).get(pasillo);
    };
    for (const { zona, pasillos } of productos ?? []) {
        if (!zonas.has(zona)) zonas.set(zona, new Map());
        for (const { pasillo, anaqueles } of pasillos) {
            const lista = pasilloDe(zona, pasillo);
            for (const { anaquel, estantes, posiciones } of anaqueles) {
                lista.set(anaquel, { estantes, posiciones });
            }
        }
    }

    const registrados = config.pasillos ?? {};
    const base = (clave) => ({
        estantes: registrados[clave]?.estantes ?? config.estantes,
        posiciones: registrados[clave]?.posiciones ?? config.posiciones,
    });
    const agregar = (zona, pasillo, anaquel, medidas) => {
        const lista = pasilloDe(zona, pasillo);
        lista.set(anaquel, { ...(lista.get(anaquel) ?? { estantes: 0, posiciones: 0 }), base: medidas });
    };
    const quitados = new Set(config.quitados ?? []);
    for (const [clave, pasillo] of Object.entries(registrados)) {
        const [zona, numero] = clave.split('-');
        for (const anaquel of anaquelesDe(pasillo)) {
            if (!quitados.has(`${clave}-${dosDigitos(anaquel)}`)) {
                agregar(zona, Number(numero), anaquel, base(clave));
            }
        }
    }
    for (const clave of config.extras ?? []) {
        const [zona, numero, anaquel] = clave.split('-');
        agregar(zona, Number(numero), Number(anaquel), base(`${zona}-${numero}`));
    }

    const ordenadas = (mapa) => [...mapa.keys()].sort((a, b) => (typeof a === 'number' ? a - b : a.localeCompare(b)));
    return {
        zonas: ordenadas(zonas).map((zona) => ({
            zona,
            pasillos: ordenadas(zonas.get(zona)).map((pasillo) => {
                const anaqueles = zonas.get(zona).get(pasillo);
                return {
                    pasillo,
                    anaqueles: ordenadas(anaqueles).map((anaquel) => {
                        const a = anaqueles.get(anaquel);
                        return {
                            anaquel,
                            estantes: Math.max(a.estantes, a.base?.estantes ?? config.estantes),
                            posiciones: Math.max(a.posiciones, a.base?.posiciones ?? config.posiciones),
                        };
                    }),
                };
            }),
        })),
    };
}

/**
 * Pasa un punto del anaquel a coordenadas del plano. lx va hacia la derecha de quien mira el
 * anaquel de frente y lz hacia el frente (el pasillo).
 */
export function enPlano(anaquel, lx, lz) {
    const t = (anaquel.giro * Math.PI) / 180;
    return [anaquel.x + lx * Math.cos(t) + lz * Math.sin(t), anaquel.z - lx * Math.sin(t) + lz * Math.cos(t)];
}

/**
 * Todos los anaqueles con su lugar, los pasillos y las zonas sin pasillos.
 *
 * @returns {{anaqueles: Array, pasillos: Array, zonasSolas: Array}}
 */
export function disponer(plano, config = configDe(plano)) {
    const anaqueles = [];
    const pasillos = [];
    const zonasSolas = [];
    const movidos = config.disposicion ?? {};
    let x = 0;

    for (const { zona, pasillos: lista } of plano.zonas) {
        if (!lista.length) {
            zonasSolas.push({ zona, x: x + ZONA_SOLA / 2, z: 0, ancho: ZONA_SOLA, fondo: ZONA_SOLA });
            x += ZONA_SOLA + ENTRE_ZONAS;
            continue;
        }

        let anchoZona = 0;
        lista.forEach(({ pasillo, anaqueles: datos }, indice) => {
            const columna = Math.max(...datos.map((a) => a.posiciones), 1) * POSICION_ANCHO;
            const maximo = Math.max(...datos.map((a) => a.anaquel), 1);
            const largo = columnasPasillo(maximo, config.numeracion) * (columna + SEPARACION);
            const z = indice * ENTRE_PASILLOS;
            let movido = false;

            for (const { anaquel, estantes, posiciones } of datos) {
                const lugar = lugarAnaquel(anaquel, maximo, config.numeracion);
                const clave = claveAnaquel(zona, pasillo, anaquel);
                const propio = movidos[clave];
                movido ||= Boolean(propio);
                anaqueles.push({
                    clave, zona, pasillo, anaquel, estantes, posiciones,
                    ancho: columna,
                    x: propio?.x ?? x + lugar.columna * (columna + SEPARACION) + columna / 2,
                    z: propio?.z ?? z + (lugar.izquierda ? -1 : 1) * (PASILLO_ANCHO / 2 + FONDO / 2),
                    giro: propio?.giro ?? (lugar.izquierda ? 0 : 180),
                    movido: Boolean(propio),
                });
            }

            pasillos.push({ zona, pasillo, x, z, largo, movido });
            anchoZona = Math.max(anchoZona, largo);
        });
        x += anchoZona + ENTRE_ZONAS;
    }

    return { anaqueles, pasillos, zonasSolas };
}

/** Rectángulo [xmin, zmin, xmax, zmax] que encierra las esquinas de unos anaqueles. */
export function limitesDe(anaqueles, margen = 0) {
    const puntos = anaqueles.flatMap((a) =>
        [[-1, -1], [1, -1], [1, 1], [-1, 1]].map(([sx, sz]) => enPlano(a, (sx * a.ancho) / 2, (sz * FONDO) / 2)),
    );
    if (!puntos.length) {
        return null;
    }
    const xs = puntos.map((p) => p[0]);
    const zs = puntos.map((p) => p[1]);
    return [Math.min(...xs) - margen, Math.min(...zs) - margen, Math.max(...xs) + margen, Math.max(...zs) + margen];
}

/**
 * Caja que ocupa una ubicación según hasta dónde llegue el código, o null si no está en el plano.
 * centro y tamaño en 3D ([x, y, z]); giro en grados; para anaqueles, además, el anaquel, el punto
 * del pasillo frente a la ubicación y los rótulos de estantes y posiciones.
 */
export function cajaDe(disposicion, ubicacion, config) {
    const zonaSola = disposicion.zonasSolas.find((z) => z.zona === ubicacion.zona);
    if (zonaSola) {
        return ubicacion.pasillo === null
            ? { centro: [zonaSola.x, 0.05, zonaSola.z], tamano: [zonaSola.ancho, 0.1, zonaSola.fondo], giro: 0, vista: 'zona' }
            : null;
    }

    const deLaZona = disposicion.anaqueles.filter((a) => a.zona === ubicacion.zona);
    const grupo = ubicacion.pasillo === null ? deLaZona : deLaZona.filter((a) => a.pasillo === ubicacion.pasillo);
    if (!grupo.length) {
        return null;
    }
    if (ubicacion.anaquel === null) {
        const [x1, z1, x2, z2] = limitesDe(grupo, 0.3);
        return { centro: [(x1 + x2) / 2, 0.05, (z1 + z2) / 2], tamano: [x2 - x1, 0.1, z2 - z1], giro: 0, vista: ubicacion.pasillo === null ? 'zona' : 'pasillo' };
    }

    const anaquel = grupo.find((a) => a.anaquel === ubicacion.anaquel);
    if (!anaquel) {
        return null;
    }
    const alto = anaquel.estantes * ESTANTE_ALTO;
    const posiciones = Math.max(anaquel.posiciones, ubicacion.posicion ?? 0);
    const hueco = anaquel.ancho / posiciones;
    const delante = FONDO / 2 + 0.05;

    const alturaEstante = (letra) => {
        const nivel = config.estanteA === 'arriba' ? Math.max(anaquel.estantes - 1 - letra, 0) : letra;
        return nivel * ESTANTE_ALTO + ESTANTE_ALTO / 2 + 0.04;
    };
    // Posición 1 a la izquierda (o a la derecha) de quien mira el anaquel de frente.
    const lado = config.posicion1 === 'izquierda' ? -1 : 1;
    const lxPosicion = (posicion) => lado * (anaquel.ancho / 2 - (posicion - 0.5) * hueco);
    const punto = (lx, y, lz) => {
        const [x, z] = enPlano(anaquel, lx, lz);
        return [x, y, z];
    };

    // Letras de los estantes a la izquierda del anaquel y números de posición arriba, para que se
    // vea cómo se cuentan en esta droguería.
    const rotulos = [
        ...Array.from({ length: anaquel.estantes }, (_, letra) => ({
            texto: String.fromCharCode(65 + letra),
            posicion: punto(-anaquel.ancho / 2 - 0.18, alturaEstante(letra), delante),
        })),
        ...Array.from({ length: posiciones }, (_, i) => ({ texto: String(i + 1), posicion: punto(lxPosicion(i + 1), alto + 0.14, delante) })),
    ];

    let lx = 0;
    let centro = punto(0, alto / 2, 0);
    let tamano = [anaquel.ancho, alto, FONDO];
    if (ubicacion.estante !== null) {
        const y = alturaEstante(ubicacion.estante.charCodeAt(0) - 65);
        centro = punto(0, y, 0);
        tamano = [anaquel.ancho, ESTANTE_ALTO * 0.85, FONDO];
        if (ubicacion.posicion !== null) {
            lx = lxPosicion(ubicacion.posicion);
            centro = punto(lx, y, 0);
            tamano = [hueco * 0.9, ESTANTE_ALTO * 0.8, FONDO * 0.9];
        }
    }

    return {
        centro, tamano, giro: anaquel.giro, vista: 'anaquel', rotulos,
        // En el piso del pasillo, frente a la ubicación.
        marca: punto(lx, 0.03, FONDO / 2 + PASILLO_ANCHO / 2),
        // Parado en el pasillo, un poco a la izquierda, como el almacenista.
        camara: punto(lx - 1.8, Math.max(1.9, centro[1] + 0.8), FONDO / 2 + PASILLO_ANCHO * 0.95),
    };
}

/** Plano de muestra para ver cómo quedaría el almacén cuando la droguería aún no manda el formato. */
export function planoEjemplo() {
    const anaqueles = (cantidad, estantes, posiciones) =>
        Array.from({ length: cantidad }, (_, i) => ({ anaquel: i + 1, estantes, posiciones }));

    return {
        zonas: [
            {
                zona: 'PK',
                pasillos: [1, 2, 3, 4].map((pasillo) => ({ pasillo, anaqueles: anaqueles(12, 4, 6) })),
            },
            {
                zona: 'MY',
                pasillos: [1, 2].map((pasillo) => ({ pasillo, anaqueles: anaqueles(6, 3, 4) })),
            },
            { zona: 'FR', pasillos: [] },
        ],
        ubicaciones: 0,
        reconocidas: 0,
    };
}
