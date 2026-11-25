import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef } from 'react';

// 3-R15: on a live event, or every 30 s while Echo isn't connected (or Reverb isn't configured), the page reloads its own
// props (Inertia partial reload: state, scroll and focus stay, so an open editor keeps its text).

export const POLL_MS = 30_000;
/** How often a deferred reload checks whether the page is free again. */
export const RESUME_MS = 250;

export type Connection = { connected: () => boolean; stop: () => void };
export type Connect = (channel: string, onEvent: () => void) => Connection;

/**
 * Reload on each event from `channel` and on a 30 s poll while not connected. While `paused()` (a drag), reloads wait and
 * then run once; events during a reload in flight make one more after it. Returns the cleanup.
 */
export function liveReload(channel: string | null, reload: (done: () => void) => void, paused: () => boolean, connect: Connect | null): () => void {
    let busy = false;
    let pending = false;
    const fire = () => {
        if (busy || paused()) return void (pending = true);
        busy = true;
        pending = false;
        reload(() => {
            busy = false;
            if (pending) fire();
        });
    };
    const connection = channel && connect ? connect(channel, fire) : null;
    const poll = setInterval(() => connection?.connected() || fire(), POLL_MS);
    const resume = setInterval(() => pending && !busy && !paused() && fire(), RESUME_MS);

    return () => {
        clearInterval(poll);
        clearInterval(resume);
        connection?.stop();
    };
}

type EchoChannel = { subscribed(cb: () => void): unknown; error(cb: (e: unknown) => void): unknown };

/**
 * Live only while the socket is up and the channel's subscription succeeded: a refused or failed channel auth leaves the
 * socket connected, and the page must keep polling then. Pusher resubscribes after a reconnect, firing these again.
 */
export function subscription(socketUp: () => boolean, channel: EchoChannel, stop: () => void): Connection {
    let subscribed = false;
    channel.subscribed(() => (subscribed = true));
    channel.error(() => (subscribed = false));
    return { connected: () => subscribed && socketUp(), stop };
}

/** Echo is loaded only when Reverb is configured, so a page without it never fetches pusher-js and only polls. */
// `?.`: under node --test there is no import.meta.env.
const echoConnect: Connect | null = import.meta.env?.VITE_REVERB_APP_KEY
    ? (channel, onEvent) => {
          let live: Connection | null = null;
          let stopped = false;
          import('./echo').then(({ subscribe }) => {
              if (!stopped) live = subscribe(channel, onEvent);
          });
          return {
              connected: () => live?.connected() ?? false,
              stop: () => {
                  stopped = true;
                  live?.stop();
              },
          };
      }
    : null;

/** Keep this page's `only` props live from the private `channel` (null: poll only). `paused` defers reloads, e.g. while dragging. */
export function useLiveReload(channel: string | null, only: string[], opts: { paused?: () => boolean } = {}): void {
    const paused = useRef(opts.paused);
    paused.current = opts.paused;
    const props = only.join(',');

    useEffect(
        () =>
            liveReload(
                channel,
                (done) => router.reload({ only: props.split(','), onFinish: done }),
                () => paused.current?.() ?? false,
                echoConnect,
            ),
        [channel, props],
    );
}

/** The 3-R14 channel of a project in the page's workspace. */
export function useProjectChannel(projectId: number): string | null {
    const workspace = usePage().props.workspace;
    return workspace ? `workspace.${workspace.id}.project.${projectId}` : null;
}
