<?php

/*
 * Instalación de SIDES de una droguería. Cada droguería tiene su propia copia de SIDES
 * conectada a su base (la misma de su SEPED). El FT (FULLTECH360) lo copia SEPED a sides_users.
 */
return [

    // codisb de la droguería de esta instalación: el login sale con su logo sin necesitar
    // el enlace /login/{codisb}. Vacío = se averigua por el enlace o por el equipo.
    'codisb' => env('SIDES_CODISB'),

    // Registros de auditoría que se conservan: al pasar de este número, cada noche se borran
    // los más antiguos (sides:depurar-auditoria). 0 = sin máximo, no se borra nada.
    'auditoria_maximo' => (int) env('SIDES_AUDITORIA_MAXIMO', 50000),

    // Servidor Reverb como lo ve un cliente de afuera (el mismo que usa el navegador, VITE_REVERB_*),
    // que la API del televisor le pasa a la app del TV para conectarse al canal en vivo.
    'reverb_publico' => [
        'key' => env('VITE_REVERB_APP_KEY', env('REVERB_APP_KEY')),
        'host' => env('VITE_REVERB_HOST', env('REVERB_HOST')),
        'port' => (int) env('VITE_REVERB_PORT', env('REVERB_PORT', 443)),
        'scheme' => env('VITE_REVERB_SCHEME', env('REVERB_SCHEME', 'https')),
    ],

];
