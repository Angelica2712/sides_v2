import './bootstrap';
import './echo';

import Alpine from 'alpinejs';
import almacenVista from './almacen';
import almacenConfig from './almacen-config';
import armarAlmacen from './armar-almacen';
import batchPicking from './batch-picking';
import despachoGuia from './despacho';
import monitorEnVivo from './monitor';
import packingPedido from './packing';
import pickingPedido from './picking';
import pickingVivo from './picking-vivo';
import tablaDesplazable from './tabla-desplazable';
import textoCortado from './texto-cortado';
import registroUbicaciones from './ubicaciones';

window.Alpine = Alpine;
textoCortado();

Alpine.data('pickingPedido', pickingPedido);
Alpine.data('almacenVista', almacenVista);
Alpine.data('almacenConfig', almacenConfig);
Alpine.data('armarAlmacen', armarAlmacen);
Alpine.data('tablaDesplazable', tablaDesplazable);
Alpine.data('packingPedido', packingPedido);
Alpine.data('batchPicking', batchPicking);
Alpine.data('despachoGuia', despachoGuia);
Alpine.data('monitorEnVivo', monitorEnVivo);
Alpine.data('pickingVivo', pickingVivo);
Alpine.data('registroUbicaciones', registroUbicaciones);

Alpine.start();
