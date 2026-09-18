import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

/**
 * SIDES v2 se conecta al mismo servidor Reverb que ya corre seped_v2 (mismas credenciales de
 * app; lo que separa a los dos sistemas son sus canales). La autorización de los canales
 * privados la resuelve esta aplicación en /broadcasting/auth, con su propia sesión.
 *
 * Sin VITE_REVERB_APP_KEY no se crea window.Echo: la aplicación funciona igual, solo que sin
 * actualización en vivo (el monitor cae a su consulta de respaldo). Eso mantiene usable un
 * entorno sin WebSocket configurado, en vez de romper toda la página con un error de JS.
 */
const clave = import.meta.env.VITE_REVERB_APP_KEY;

if (clave) {
    window.Pusher = Pusher;

    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: clave,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
    });
}
