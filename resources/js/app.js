import './bootstrap';

import Alpine from 'alpinejs';
import batchPicking from './batch-picking';
import despachoGuia from './despacho';
import packingPedido from './packing';
import pickingPedido from './picking';
import tablaDesplazable from './tabla-desplazable';

window.Alpine = Alpine;

Alpine.data('pickingPedido', pickingPedido);
Alpine.data('tablaDesplazable', tablaDesplazable);
Alpine.data('packingPedido', packingPedido);
Alpine.data('batchPicking', batchPicking);
Alpine.data('despachoGuia', despachoGuia);

Alpine.start();
