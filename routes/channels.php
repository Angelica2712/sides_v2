<?php

use App\Support\MenuSides;
use Illuminate\Support\Facades\Broadcast;

/**
 * Canal del monitor en vivo (ver app/Events/MonitorActualizado.php).
 *
 * La autorización la resuelve SIDES v2 con su propia sesión y sus propios usuarios
 * (sides_users): el navegador pide /broadcasting/auth a esta aplicación, no a seped_v2,
 * aunque el servidor Reverb sea el mismo. Reverb solo valida la firma hecha con
 * REVERB_APP_SECRET, que las dos aplicaciones comparten.
 *
 * Misma regla que protege la ruta /monitor (middleware `permiso:monitor`), más la
 * sucursal: un usuario solo escucha los pedidos de su propia droguería.
 */
Broadcast::channel('sides-monitor.{codisb}', function ($usuario, string $codisb) {
    if ((string) $usuario->codisb !== $codisb) {
        return false;
    }

    return MenuSides::puede($usuario, $usuario->cfg, 'monitor');
});
