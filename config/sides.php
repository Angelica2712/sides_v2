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

];
