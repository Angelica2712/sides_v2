/**
 * Visor 3D del almacén (three.js). Dibuja el plano que arma app/Services/Almacen/PlanoAlmacen.php,
 * con cada anaquel donde lo pone ./almacen-plano.js (acomodo automático o movido a mano en
 * Administración), y resalta ubicaciones en el formato de ./ubicacion.js.
 *
 * Con permitirMover() la caja naranja (la ubicación resaltada) se puede agarrar y soltar en otro
 * hueco de cualquier anaquel; el visor dice a qué ubicación cayó y quien lo usa decide si la guarda.
 *
 * three.js se carga recién al abrir el visor, para no pesar en las demás pantallas.
 */
import { cajaDe, claveAnaquel, disponer, enPlano, ESTANTE_ALTO, FONDO, limitesDe, PASILLO_ANCHO } from './almacen-plano';
import { configDe, interpretar, nombreZona } from './ubicacion';

export { planoEjemplo } from './almacen-plano';

const COLORES = {
    piso: 0xe2e8f0,
    pasillo: 0xf8fafc,
    estructura: 0x475569,
    tabla: 0x94a3b8,
    caja: 0xcbd5e1,
    principal: 0xf97316,
    otros: 0x0ea5e9,
    destino: 0x16a34a,
    entrada: 0x15803d,
};

/** Número pseudoaleatorio estable, para que las cajas de relleno no cambien entre visitas. */
function azar(...semillas) {
    let h = 2166136261;
    for (const s of semillas) {
        h = Math.imul(h ^ s, 16777619);
    }
    return ((h >>> 0) % 1000) / 1000;
}

export async function crearVisor(contenedor, plano) {
    const [THREE, { MapControls }] = await Promise.all([
        import('three'),
        import('three/examples/jsm/controls/MapControls.js'),
    ]);

    const config = configDe(plano);
    const disposicion = disponer(plano, config);
    const escena = new THREE.Scene();
    escena.background = new THREE.Color(0xf1f5f9);

    const camara = new THREE.PerspectiveCamera(55, 1, 0.1, 500);
    const render = new THREE.WebGLRenderer({ antialias: true });
    render.setPixelRatio(Math.min(window.devicePixelRatio, 2));
    contenedor.appendChild(render.domElement);
    render.domElement.style.display = 'block';
    render.domElement.style.touchAction = 'none';

    // Como un mapa: arrastrar (un dedo o clic izquierdo) desplaza sobre el piso; clic derecho o dos
    // dedos giran; la rueda o pellizcar acercan. Con el visor enfocado, las flechas también desplazan.
    const controles = new MapControls(camara, render.domElement);
    controles.enableDamping = true;
    controles.maxPolarAngle = Math.PI / 2 - 0.05;
    controles.minDistance = 0.8;
    controles.maxDistance = 120;
    controles.zoomToCursor = true;
    controles.keyPanSpeed = 25;
    render.domElement.tabIndex = 0;
    render.domElement.style.outline = 'none';
    render.domElement.addEventListener('contextmenu', (evento) => evento.preventDefault());
    controles.listenToKeyEvents(render.domElement);

    escena.add(new THREE.HemisphereLight(0xffffff, 0x94a3b8, 1.6));
    const sol = new THREE.DirectionalLight(0xffffff, 1.2);
    sol.position.set(20, 30, 15);
    escena.add(sol);

    const material = (color, extra = {}) => new THREE.MeshLambertMaterial({ color, ...extra });
    const cubo = new THREE.BoxGeometry(1, 1, 1);
    const desechables = [cubo];
    const ejeY = new THREE.Vector3(0, 1, 0);
    const giroY = (grados) => new THREE.Quaternion().setFromAxisAngle(ejeY, (grados * Math.PI) / 180);

    // Piezas repetidas en InstancedMesh: un almacén puede tener miles de tablas y cajas.
    const piezas = { estructura: [], tabla: [], caja: [], pasillo: [] };
    const agregar = (tipo, [x, y, z], [w, h, d], grados = 0) => {
        piezas[tipo].push(new THREE.Matrix4().compose(new THREE.Vector3(x, y, z), giroY(grados), new THREE.Vector3(w, h, d)));
    };

    const etiqueta = (texto, { tamano = 0.5, color = '#0f172a', fondo = 'rgba(255,255,255,0.9)', lista = desechables } = {}) => {
        const lienzo = document.createElement('canvas');
        const ctx = lienzo.getContext('2d');
        ctx.font = 'bold 64px system-ui, sans-serif';
        lienzo.width = Math.ceil(ctx.measureText(texto).width) + 48;
        lienzo.height = 96;
        ctx.font = 'bold 64px system-ui, sans-serif';
        ctx.fillStyle = fondo;
        ctx.beginPath();
        ctx.roundRect(0, 0, lienzo.width, lienzo.height, 24);
        ctx.fill();
        ctx.fillStyle = color;
        ctx.textBaseline = 'middle';
        ctx.fillText(texto, 24, 50);
        const textura = new THREE.CanvasTexture(lienzo);
        textura.colorSpace = THREE.SRGBColorSpace;
        const sprite = new THREE.Sprite(new THREE.SpriteMaterial({ map: textura, depthTest: false }));
        sprite.scale.set((tamano * lienzo.width) / lienzo.height, tamano, 1);
        sprite.renderOrder = 10;
        lista.push(textura, sprite.material);
        return sprite;
    };
    const rotular = (texto, [x, y, z], opciones) => {
        const sprite = etiqueta(texto, opciones);
        sprite.position.set(x, y, z);
        escena.add(sprite);
    };

    // Anaqueles: estructura, tablas y cajas de relleno, girados según su disposición.
    for (const a of disposicion.anaqueles) {
        const alto = a.estantes * ESTANTE_ALTO;
        const punto = (lx, y, lz) => {
            const [x, z] = enPlano(a, lx, lz);
            return [x, y, z];
        };

        for (const sx of [-1, 1]) {
            for (const sz of [-1, 1]) {
                agregar('estructura', punto((sx * a.ancho) / 2, (alto + 0.1) / 2, (sz * FONDO) / 2), [0.04, alto + 0.1, 0.04], a.giro);
            }
        }
        for (let e = 0; e <= a.estantes; e++) {
            agregar('tabla', punto(0, e * ESTANTE_ALTO + 0.02, 0), [a.ancho, 0.04, FONDO], a.giro);
        }
        const hueco = a.ancho / a.posiciones;
        for (let e = 0; e < a.estantes; e++) {
            for (let p = 0; p < a.posiciones; p++) {
                if (azar(a.anaquel, a.pasillo, e, p) < 0.2) {
                    continue;
                }
                const h = ESTANTE_ALTO * (0.4 + 0.4 * azar(p, e, a.anaquel, 7));
                agregar('caja', punto(-a.ancho / 2 + (p + 0.5) * hueco, e * ESTANTE_ALTO + 0.04 + h / 2, 0), [hueco * 0.8, h, FONDO * 0.75], a.giro);
            }
        }
        rotular(String(a.anaquel).padStart(2, '0'), punto(0, alto + 0.45, 0), { tamano: 0.24 });
    }

    // Pasillos: el piso claro solo si ninguno de sus anaqueles se movió a mano; el cartel en la
    // entrada o, si se movieron, encima de sus anaqueles.
    for (const pasillo of disposicion.pasillos) {
        const nombre = `Pasillo ${String(pasillo.pasillo).padStart(2, '0')}`;
        if (!pasillo.movido) {
            agregar('pasillo', [pasillo.x + pasillo.largo / 2, 0.01, pasillo.z], [pasillo.largo, 0.02, PASILLO_ANCHO]);
            rotular(nombre, [pasillo.x - 0.9, 0.5, pasillo.z], { tamano: 0.4 });
            continue;
        }
        const suyos = disposicion.anaqueles.filter((a) => a.zona === pasillo.zona && a.pasillo === pasillo.pasillo);
        const [x1, z1, x2, z2] = limitesDe(suyos);
        const alto = Math.max(...suyos.map((a) => a.estantes)) * ESTANTE_ALTO;
        rotular(nombre, [(x1 + x2) / 2, alto + 0.9, (z1 + z2) / 2], { tamano: 0.4 });
    }

    const limites = new THREE.Box3();
    const zonas = [...new Set([...disposicion.anaqueles.map((a) => a.zona), ...disposicion.zonasSolas.map((z) => z.zona)])];
    for (const zona of zonas) {
        const nombre = nombreZona(zona, config) === zona ? zona : `${zona} · ${nombreZona(zona, config)}`;
        const sola = disposicion.zonasSolas.find((z) => z.zona === zona);
        const [x1, z1, x2, z2] = sola
            ? [sola.x - sola.ancho / 2, sola.z - sola.fondo / 2, sola.x + sola.ancho / 2, sola.z + sola.fondo / 2]
            : limitesDe(disposicion.anaqueles.filter((a) => a.zona === zona));
        if (sola) {
            agregar('pasillo', [sola.x, 0.01, sola.z], [sola.ancho, 0.02, sola.fondo]);
        }
        rotular(nombre, [(x1 + x2) / 2, 4, z1], { tamano: 0.7, color: '#ffffff', fondo: 'rgba(15,23,42,0.85)' });
        limites.expandByPoint(new THREE.Vector3(x1 - 1.5, 0, z1));
        limites.expandByPoint(new THREE.Vector3(x2, 3, z2));
    }

    // Entradas del almacén (config.entradas): marco verde de la puerta, una alfombra hacia adentro y su nombre.
    const materialEntrada = material(COLORES.entrada);
    const materialAlfombra = material(COLORES.entrada, { transparent: true, opacity: 0.35 });
    desechables.push(materialEntrada, materialAlfombra);
    for (const e of config.entradas ?? []) {
        const pieza = (mat, [lx, y, lz], escala) => {
            const [x, z] = enPlano(e, lx, lz);
            const malla = new THREE.Mesh(cubo, mat);
            malla.position.set(x, y, z);
            malla.quaternion.copy(giroY(e.giro));
            malla.scale.set(...escala);
            escena.add(malla);
        };
        pieza(materialEntrada, [-0.65, 1.05, 0], [0.1, 2.1, 0.15]);
        pieza(materialEntrada, [0.65, 1.05, 0], [0.1, 2.1, 0.15]);
        pieza(materialEntrada, [0, 2.15, 0], [1.4, 0.12, 0.15]);
        pieza(materialAlfombra, [0, 0.02, 0.6], [1.2, 0.02, 1.2]);
        rotular(e.nombre || 'Entrada', [e.x, 2.6, e.z], { tamano: 0.4, color: '#ffffff', fondo: 'rgba(21,128,61,0.9)' });
        limites.expandByPoint(new THREE.Vector3(e.x - 2.5, 0, e.z - 2.5));
        limites.expandByPoint(new THREE.Vector3(e.x + 2.5, 3, e.z + 2.5));
    }

    // Volumen de cada anaquel, sin dibujarse: sirve para saber qué hueco hay bajo el puntero.
    const volumenes = new THREE.InstancedMesh(
        cubo,
        new THREE.MeshBasicMaterial({ colorWrite: false, depthWrite: false }),
        Math.max(disposicion.anaqueles.length, 1),
    );
    volumenes.count = disposicion.anaqueles.length;
    disposicion.anaqueles.forEach((a, i) => {
        const alto = a.estantes * ESTANTE_ALTO;
        volumenes.setMatrixAt(i, new THREE.Matrix4().compose(new THREE.Vector3(a.x, alto / 2, a.z), giroY(a.giro), new THREE.Vector3(a.ancho, alto, FONDO)));
    });
    volumenes.computeBoundingSphere();
    desechables.push(volumenes.material);
    escena.add(volumenes);

    const colores = { estructura: COLORES.estructura, tabla: COLORES.tabla, caja: COLORES.caja, pasillo: COLORES.pasillo };
    for (const [tipo, matrices] of Object.entries(piezas)) {
        if (!matrices.length) {
            continue;
        }
        const malla = new THREE.InstancedMesh(cubo, material(colores[tipo]), matrices.length);
        matrices.forEach((matriz, i) => malla.setMatrixAt(i, matriz));
        desechables.push(malla.material);
        escena.add(malla);
    }

    if (limites.isEmpty()) {
        limites.set(new THREE.Vector3(-2, 0, -2), new THREE.Vector3(2, 1, 2));
    }
    const centro = limites.getCenter(new THREE.Vector3());
    const tamano = limites.getSize(new THREE.Vector3());
    const piso = new THREE.Mesh(new THREE.PlaneGeometry(tamano.x + 8, tamano.z + 8), material(COLORES.piso));
    piso.rotation.x = -Math.PI / 2;
    piso.position.set(centro.x, 0, centro.z);
    desechables.push(piso.geometry, piso.material);
    escena.add(piso);

    // Resaltados: la caja naranja es la ubicación buscada, las celestes las que siguen.
    const resaltados = new THREE.Group();
    escena.add(resaltados);
    const materialPrincipal = material(COLORES.principal, { emissive: 0x9a3412, transparent: true, opacity: 0.9 });
    const materialOtros = material(COLORES.otros, { emissive: 0x075985, transparent: true, opacity: 0.55 });
    const materialMarca = new THREE.MeshBasicMaterial({ color: COLORES.principal, side: THREE.DoubleSide });
    const aro = new THREE.RingGeometry(0.22, 0.3, 40);
    desechables.push(materialPrincipal, materialOtros, materialMarca, aro);
    let principal = null;
    let marca = null;
    // Texturas de los rótulos del anaquel resaltado; se liberan al resaltar otro.
    const rotulos = [];

    // Vuelo suave de la cámara hacia un punto.
    let vuelo = null;
    const volar = (posicion, objetivo, duracion = 900) => {
        vuelo = { desde: [camara.position.clone(), controles.target.clone()], hasta: [posicion, objetivo], inicio: performance.now(), duracion };
    };
    // Al desplazarse no se puede salir del almacén (con un margen): así nadie se pierde en el vacío.
    const zonaPermitida = limites.clone().expandByScalar(8);
    /** Lo que hay que correr un punto del piso para que quede dentro de la zona permitida. */
    const corrimientoPara = ({ x, z }) => new THREE.Vector3(
        Math.min(Math.max(x, zonaPermitida.min.x), zonaPermitida.max.x) - x,
        0,
        Math.min(Math.max(z, zonaPermitida.min.z), zonaPermitida.max.z) - z,
    );
    // Arrastrando: el borde frena el desplazamiento.
    const mantenerDentro = () => {
        const corrimiento = corrimientoPara(controles.target);
        if (corrimiento.lengthSq()) {
            controles.target.add(corrimiento);
            camara.position.add(corrimiento);
        }
    };

    /**
     * Botones de la pantalla: desplazarse sobre el piso según hacia dónde mira la cámara, girar
     * alrededor del punto que se mira y acercar o alejar.
     */
    const navegar = (accion) => {
        const objetivo = vuelo ? vuelo.hasta[1].clone() : controles.target.clone();
        const posicion = vuelo ? vuelo.hasta[0].clone() : camara.position.clone();
        const mirada = objetivo.clone().sub(posicion);
        const distancia = mirada.length();
        const adelante = new THREE.Vector3(mirada.x, 0, mirada.z).normalize();
        const derecha = new THREE.Vector3(-adelante.z, 0, adelante.x);
        const paso = Math.min(Math.max(distancia * 0.12, 1), 5);
        // El destino se recorta al borde antes de volar: si se volara más allá, al llegar
        // mantenerDentro lo devolvería de golpe.
        const desplazar = (vector) => {
            objetivo.add(vector);
            const corrimiento = corrimientoPara(objetivo);
            vector.add(corrimiento);
            if (vector.lengthSq() < 1e-6) {
                return;
            }
            volar(posicion.add(vector), objetivo.add(corrimiento), 350);
        };

        if (accion === 'izquierda') desplazar(derecha.multiplyScalar(-paso));
        if (accion === 'derecha') desplazar(derecha.multiplyScalar(paso));
        if (accion === 'adelante') desplazar(adelante.multiplyScalar(paso));
        if (accion === 'atras') desplazar(adelante.multiplyScalar(-paso));
        if (accion === 'girarIzquierda' || accion === 'girarDerecha') {
            const angulo = (accion === 'girarIzquierda' ? -1 : 1) * (Math.PI / 6);
            const brazo = posicion.clone().sub(objetivo).applyAxisAngle(ejeY, angulo);
            volar(objetivo.clone().add(brazo), objetivo, 350);
        }
        if (accion === 'acercar' || accion === 'alejar') {
            const factor = accion === 'acercar' ? 0.65 : 1.5;
            const nueva = Math.min(Math.max(distancia * factor, controles.minDistance), controles.maxDistance);
            volar(objetivo.clone().sub(mirada.normalize().multiplyScalar(nueva)), objetivo, 350);
        }
    };
    const vistaGeneral = (inmediata = false) => {
        const distancia = Math.max(tamano.x, tamano.z, 6) * 0.9;
        const posicion = new THREE.Vector3(centro.x - distancia * 0.35, distancia * 0.75, centro.z + distancia);
        if (inmediata) {
            camara.position.copy(posicion);
            controles.target.copy(centro);
        } else {
            volar(posicion, centro.clone());
        }
    };

    const ajustar = () => {
        const { clientWidth: ancho, clientHeight: alto } = contenedor;
        if (!ancho || !alto) {
            return;
        }
        render.setSize(ancho, alto);
        camara.aspect = ancho / alto;
        // En pantallas angostas (el teléfono en vertical) se abre el ángulo para que entre el anaquel:
        // la cámara no puede alejarse más sin quedar detrás del anaquel de enfrente.
        camara.fov = camara.aspect < 1 ? Math.min(80, 55 / Math.sqrt(camara.aspect)) : 55;
        camara.updateProjectionMatrix();
    };
    const observador = new ResizeObserver(ajustar);
    observador.observe(contenedor);
    ajustar();
    vistaGeneral(true);

    let cuadro = 0;
    let reloj = 0;
    const animar = () => {
        cuadro = requestAnimationFrame(animar);
        reloj += 0.05;
        if (vuelo) {
            const t = Math.min((performance.now() - vuelo.inicio) / vuelo.duracion, 1);
            const k = 1 - (1 - t) ** 3;
            camara.position.lerpVectors(vuelo.desde[0], vuelo.hasta[0], k);
            controles.target.lerpVectors(vuelo.desde[1], vuelo.hasta[1], k);
            if (t === 1) {
                vuelo = null;
            }
        }
        if (principal) {
            principal.scale.setScalar(1 + 0.06 * Math.sin(reloj * 2));
        }
        if (marca) {
            marca.scale.setScalar(1 + 0.25 * Math.sin(reloj * 1.5));
        }
        controles.update();
        if (!vuelo) {
            mantenerDentro();
        }
        render.render(escena, camara);
    };
    animar();

    const caja = (ubicacion, mat) => {
        const datos = cajaDe(disposicion, ubicacion, config);
        if (!datos) {
            return null;
        }
        // El pulso se aplica al grupo; la malla conserva el tamaño de la ubicación.
        const grupo = new THREE.Group();
        grupo.position.set(...datos.centro);
        grupo.quaternion.copy(giroY(datos.giro));
        const malla = new THREE.Mesh(cubo, mat);
        malla.scale.set(...datos.tamano);
        grupo.add(malla);
        resaltados.add(grupo);
        return { grupo, datos };
    };

    // ---- Agarrar la caja naranja y soltarla en otro hueco ----

    const rayo = new THREE.Raycaster();
    const puntero = new THREE.Vector2();
    const destino = new THREE.Group();
    escena.add(destino);
    const materialDestino = material(COLORES.destino, { emissive: 0x14532d, transparent: true, opacity: 0.85 });
    desechables.push(materialDestino);
    let alSoltar = null;
    let arrastre = null;

    const apuntar = (evento) => {
        const borde = render.domElement.getBoundingClientRect();
        puntero.set(((evento.clientX - borde.left) / borde.width) * 2 - 1, -((evento.clientY - borde.top) / borde.height) * 2 + 1);
        rayo.setFromCamera(puntero, camara);
    };
    const tocaPrincipal = (evento) => {
        apuntar(evento);
        return Boolean(principal) && rayo.intersectObject(principal, true).length > 0;
    };

    /** Ubicación completa (con estante y posición) del hueco bajo el puntero, o null. */
    const huecoBajo = (evento) => {
        apuntar(evento);
        const [golpe] = rayo.intersectObject(volumenes, false);
        if (!golpe) {
            return null;
        }
        const a = disposicion.anaqueles[golpe.instanceId];
        // Del plano a las coordenadas del anaquel (la inversa de enPlano).
        const t = (a.giro * Math.PI) / 180;
        const lx = (golpe.point.x - a.x) * Math.cos(t) - (golpe.point.z - a.z) * Math.sin(t);
        const limitar = (valor, maximo) => Math.min(Math.max(valor, 0), maximo);
        const nivel = limitar(Math.floor((golpe.point.y - 0.04) / ESTANTE_ALTO), a.estantes - 1);
        const letra = config.estanteA === 'arriba' ? a.estantes - 1 - nivel : nivel;
        const lado = config.posicion1 === 'izquierda' ? -1 : 1;
        const posicion = limitar(Math.floor((a.ancho / 2 - lado * lx) / (a.ancho / a.posiciones)), a.posiciones - 1) + 1;

        return `${claveAnaquel(a.zona, a.pasillo, a.anaquel)}-${String.fromCharCode(65 + letra)}-${String(posicion).padStart(2, '0')}`;
    };

    const marcarDestino = (codigo) => {
        destino.clear();
        const datos = codigo && cajaDe(disposicion, interpretar(codigo), config);
        if (!datos) {
            return;
        }
        const malla = new THREE.Mesh(cubo, materialDestino);
        malla.position.set(...datos.centro);
        malla.quaternion.copy(giroY(datos.giro));
        malla.scale.set(...datos.tamano);
        destino.add(malla);
    };

    // Se escucha en el contenedor, en captura: llega antes que a OrbitControls, que gira la cámara
    // con cualquier arrastre. Si el dedo cae sobre la caja naranja, la cámara se queda quieta.
    const presionar = (evento) => {
        if (!alSoltar || evento.button > 0 || !tocaPrincipal(evento)) {
            return;
        }
        evento.stopPropagation();
        controles.enabled = false;
        arrastre = { codigo: null };
        contenedor.setPointerCapture(evento.pointerId);
        render.domElement.style.cursor = 'grabbing';
    };
    const mover = (evento) => {
        if (!arrastre) {
            if (alSoltar && evento.pointerType === 'mouse') {
                render.domElement.style.cursor = tocaPrincipal(evento) ? 'grab' : '';
            }
            return;
        }
        const codigo = huecoBajo(evento);
        if (codigo !== arrastre.codigo) {
            arrastre.codigo = codigo;
            marcarDestino(codigo);
        }
    };
    const soltar = () => {
        if (!arrastre) {
            return;
        }
        const { codigo } = arrastre;
        arrastre = null;
        controles.enabled = true;
        render.domElement.style.cursor = '';
        if (codigo) {
            alSoltar(codigo);
        } else {
            destino.clear();
        }
    };
    contenedor.addEventListener('pointerdown', presionar, true);
    contenedor.addEventListener('pointermove', mover, true);
    contenedor.addEventListener('pointerup', soltar, true);
    contenedor.addEventListener('pointercancel', soltar, true);

    return {
        /**
         * Deja agarrar la caja naranja y soltarla en otro hueco: alSoltar recibe la ubicación
         * completa donde cayó (la caja verde queda marcada hasta limpiarDestino). null lo apaga.
         */
        permitirMover(funcion) {
            alSoltar = funcion;
            if (!funcion) {
                destino.clear();
                render.domElement.style.cursor = '';
            }
        },
        limpiarDestino: () => destino.clear(),
        /**
         * Resalta la ubicación buscada (texto o ya interpretada) y, más tenues, las siguientes.
         * Devuelve false si la ubicación no está en el plano.
         */
        resaltar(buscada, siguientes = []) {
            resaltados.clear();
            destino.clear();
            rotulos.splice(0).forEach((recurso) => recurso.dispose());
            principal = null;
            marca = null;

            for (const texto of siguientes) {
                const ubicacion = typeof texto === 'string' ? interpretar(texto) : texto;
                if (ubicacion) {
                    caja(ubicacion, materialOtros);
                }
            }

            const ubicacion = typeof buscada === 'string' ? interpretar(buscada) : buscada;
            const encontrada = ubicacion ? caja(ubicacion, materialPrincipal) : null;
            if (!encontrada) {
                vistaGeneral();
                return false;
            }

            principal = encontrada.grupo;
            const { datos } = encontrada;
            const objetivo = new THREE.Vector3(...datos.centro);

            if (datos.vista === 'anaquel') {
                marca = new THREE.Mesh(aro, materialMarca);
                marca.rotation.x = -Math.PI / 2;
                marca.position.set(...datos.marca);
                resaltados.add(marca);
                for (const { texto, posicion } of datos.rotulos) {
                    const rotulo = etiqueta(texto, { tamano: 0.16, color: '#9a3412', fondo: 'rgba(255,237,213,0.95)', lista: rotulos });
                    rotulo.position.set(...posicion);
                    resaltados.add(rotulo);
                }
                volar(new THREE.Vector3(...datos.camara), objetivo);
            } else {
                const [x, , z] = datos.centro;
                const distancia = Math.max(...datos.tamano, 4);
                volar(new THREE.Vector3(x - distancia * 0.3, distancia * 0.9, z + distancia * 1.1), objetivo);
            }
            return true;
        },
        vistaGeneral: () => vistaGeneral(),
        /** izquierda, derecha, adelante, atras, girarIzquierda, girarDerecha, acercar o alejar. */
        navegar,
        destruir() {
            cancelAnimationFrame(cuadro);
            observador.disconnect();
            contenedor.removeEventListener('pointerdown', presionar, true);
            contenedor.removeEventListener('pointermove', mover, true);
            contenedor.removeEventListener('pointerup', soltar, true);
            contenedor.removeEventListener('pointercancel', soltar, true);
            controles.dispose();
            escena.traverse((objeto) => objeto.isInstancedMesh && objeto.dispose());
            [...desechables, ...rotulos].forEach((recurso) => recurso.dispose());
            render.dispose();
            render.domElement.remove();
        },
    };
}
