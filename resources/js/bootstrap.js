import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// Laravel Echo + pusher-js, speaking the pusher protocol to our own
// self-hosted Reverb websocket server (not pusher.com — we never reach
// out to the internet). Config values come in via VITE_REVERB_* so the
// browser bundle knows where to open the socket. Exposing $echo on
// window lets Livewire's #[On('echo:...')] listener pick it up without
// any extra wiring, and lets custom Alpine snippets subscribe directly
// via `window.Echo.channel(...)`.
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

// Reverb connection details are baked into `window.__ORBITAL_ECHO__` by the
// server at request time (see resources/views/partials/echo-config.blade.php),
// derived from APP_URL + the configured Reverb app key. Orbital ships as one
// distributable image to many customers, each with a different host/key, so
// these can't be baked in at Vite build time. import.meta.env.VITE_REVERB_*
// remains as a fallback for local `sail`/`vite dev` usage where the runtime
// partial isn't rendered (e.g. plain asset serving outside a Blade page).
const echoConfig = window.__ORBITAL_ECHO__ ?? {};

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: echoConfig.key ?? import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: echoConfig.wsHost ?? import.meta.env.VITE_REVERB_HOST,
    wsPort: echoConfig.wsPort ?? (import.meta.env.VITE_REVERB_PORT ?? 80),
    wssPort: echoConfig.wsPort ?? (import.meta.env.VITE_REVERB_PORT ?? 443),
    forceTLS: echoConfig.forceTLS ?? ((import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https'),
    enabledTransports: ['ws', 'wss'],
});

// Filament's notifications Livewire component subscribes to the
// user's private broadcast channel on an `EchoLoaded` event. Because
// this file is an ES module (deferred), it can finish executing
// AFTER the Livewire @script tag has already run and missed the
// `if (window.Echo)` check. Without this dispatch, the toast stack
// never subscribes on pages where module load loses the race.
// Observed on the operator panel: database-notification drawer
// worked, but broadcast toasts silently failed.
window.dispatchEvent(new CustomEvent('EchoLoaded'));
