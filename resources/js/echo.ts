import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import type { Connection } from './useLiveReload';

// Loaded by useLiveReload only when VITE_REVERB_APP_KEY is set (3-R15).
let echo: Echo<'reverb'> | null = null;

/** The XSRF-TOKEN cookie Laravel refreshes on every response; a meta tag would go stale after signing in. */
const xsrf = () => decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1] ?? '');

function client(): Echo<'reverb'> {
    const env = import.meta.env;
    echo ??= new Echo({
        broadcaster: 'reverb',
        Pusher,
        key: env.VITE_REVERB_APP_KEY,
        wsHost: env.VITE_REVERB_HOST,
        wsPort: Number(env.VITE_REVERB_PORT ?? 80),
        wssPort: Number(env.VITE_REVERB_PORT ?? 443),
        forceTLS: (env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
        authorizer: (channel: { name: string }) => ({
            authorize: (socketId: string, callback: (error: Error | null, data: { auth: string } | null) => void) => {
                fetch('/broadcasting/auth', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
                    body: JSON.stringify({ socket_id: socketId, channel_name: channel.name }),
                })
                    .then((r) => (r.ok ? r.json() : Promise.reject(new Error(`Channel auth failed (${r.status})`))))
                    .then((data) => callback(null, data), (error) => callback(error, null));
            },
        }),
    });
    return echo;
}

/** Listen for "changed" on the private `channel` until stopped. */
export function subscribe(channel: string, onEvent: () => void): Connection {
    const echo = client();
    echo.private(channel).listen('.changed', onEvent);
    return { connected: () => echo.connectionStatus() === 'connected', stop: () => echo.leave(channel) };
}
