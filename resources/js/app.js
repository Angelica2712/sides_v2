import './bootstrap';
import './echo';

import Alpine from 'alpinejs';
import batchPicking from './batch-picking';
import despachoGuia from './despacho';
import monitorEnVivo from './monitor';
import packingPedido from './packing';
import pickingPedido from './picking';
import tablaDesplazable from './tabla-desplazable';
import textoCortado from './texto-cortado';

window.Alpine = Alpine;
textoCortado();

Alpine.data('pickingPedido', pickingPedido);
Alpine.data('tablaDesplazable', tablaDesplazable);
Alpine.data('packingPedido', packingPedido);
Alpine.data('batchPicking', batchPicking);
Alpine.data('despachoGuia', despachoGuia);
Alpine.data('monitorEnVivo', monitorEnVivo);

Alpine.start();
