<?php

/*
 * API de pedidos para el SIAD (ERP de la droguería). Ver App\Http\Controllers\SiadApiController.
 *
 * El SIAD instalado no manda cabeceras: solo concatena la ruta a su "dominioapi". Por eso el
 * token va en la URL y su dominioapi queda https://<host-sides>/api/siad/<token>.
 */
return [

    // Tokens aceptados, separados por coma (uno por droguería o por SIAD). Vacío = nadie entra.
    'tokens' => array_values(array_filter(array_map('trim', explode(',', (string) env('SIAD_API_TOKENS', ''))))),

    // Las mismas rutas sin token, en el path del legacy (dominioapi https://<host>/api). Solo pruebas.
    'abierta' => (bool) env('SIAD_API_ABIERTA', false),

    // Pedidos en PEND-FACTURA que get_pedido revisa para saltar los incompletos.
    'max_revisados' => 20,

];
