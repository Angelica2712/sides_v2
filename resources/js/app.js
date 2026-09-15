import './bootstrap';

import Alpine from 'alpinejs';
import batchPicking from './batch-picking';
import packingPedido from './packing';
import pickingPedido from './picking';

window.Alpine = Alpine;

Alpine.data('pickingPedido', pickingPedido);
Alpine.data('packingPedido', packingPedido);
Alpine.data('batchPicking', batchPicking);

Alpine.start();
