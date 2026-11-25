import assert from 'node:assert/strict';
import test from 'node:test';
import { liveReload, POLL_MS, RESUME_MS, subscription, type Connect } from './useLiveReload.ts';

/** A reload that finishes at once, counting calls. */
function counter() {
    const calls = { n: 0 };
    return { calls, reload: (done: () => void) => (calls.n++, done()) };
}

/** A fake Echo: `send()` delivers an event; `up` says whether it is connected. */
function fakeEcho() {
    const state = { up: true, send: () => {}, stopped: false, channel: '' };
    const connect: Connect = (channel, onEvent) => {
        state.channel = channel;
        state.send = onEvent;
        return { connected: () => state.up, stop: () => void (state.stopped = true) };
    };
    return { state, connect };
}

test('without Echo it polls every 30 s', (t) => {
    t.mock.timers.enable({ apis: ['setInterval'] });
    const { calls, reload } = counter();
    const stop = liveReload('workspace.1.project.2', reload, () => false, null);

    t.mock.timers.tick(POLL_MS - 1);
    assert.equal(calls.n, 0);
    t.mock.timers.tick(1);
    assert.equal(calls.n, 1);
    t.mock.timers.tick(POLL_MS);
    assert.equal(calls.n, 2);
    stop();
    t.mock.timers.tick(POLL_MS * 3);
    assert.equal(calls.n, 2);
    assert.equal(POLL_MS, 30_000);
});

test('with no channel it only polls', (t) => {
    t.mock.timers.enable({ apis: ['setInterval'] });
    const { calls, reload } = counter();
    const echo = fakeEcho();
    liveReload(null, reload, () => false, echo.connect)();

    assert.equal(echo.state.channel, '');
    assert.equal(calls.n, 0);
});

test('connected, it stops polling and reloads on each event; dropped, it polls again', (t) => {
    t.mock.timers.enable({ apis: ['setInterval'] });
    const { calls, reload } = counter();
    const echo = fakeEcho();
    const stop = liveReload('user.7', reload, () => false, echo.connect);

    assert.equal(echo.state.channel, 'user.7');
    t.mock.timers.tick(POLL_MS * 3);
    assert.equal(calls.n, 0);
    echo.state.send();
    assert.equal(calls.n, 1);

    echo.state.up = false;
    t.mock.timers.tick(POLL_MS);
    assert.equal(calls.n, 2);

    stop();
    assert.equal(echo.state.stopped, true);
});

test('while paused it defers, then reloads once', (t) => {
    t.mock.timers.enable({ apis: ['setInterval'] });
    const { calls, reload } = counter();
    const echo = fakeEcho();
    let dragging = true;
    const stop = liveReload('workspace.1.project.2', reload, () => dragging, echo.connect);

    echo.state.send();
    echo.state.send();
    t.mock.timers.tick(RESUME_MS * 4);
    assert.equal(calls.n, 0);

    dragging = false;
    t.mock.timers.tick(RESUME_MS);
    assert.equal(calls.n, 1);
    t.mock.timers.tick(RESUME_MS * 4);
    assert.equal(calls.n, 1);
    stop();
});

test('events during a reload in flight make one more reload after it, not one each', (t) => {
    t.mock.timers.enable({ apis: ['setInterval'] });
    let finish = () => {};
    let n = 0;
    const echo = fakeEcho();
    const stop = liveReload('workspace.1.project.2', (done) => (n++, (finish = done)), () => false, echo.connect);

    echo.state.send();
    echo.state.send();
    echo.state.send();
    assert.equal(n, 1);
    finish();
    assert.equal(n, 2);
    finish();
    assert.equal(n, 2);
    stop();
});

/** A fake Echo private channel: `succeed()` / `fail()` fire its subscription callbacks. */
function fakeChannel() {
    const on: { ok?: () => void; error?: (e: unknown) => void } = {};
    const channel = {
        subscribed: (cb: () => void) => ((on.ok = cb), channel),
        error: (cb: (e: unknown) => void) => ((on.error = cb), channel),
    };
    return { channel, succeed: () => on.ok!(), fail: () => on.error!({ status: 403 }) };
}

test('a connected socket whose channel subscription fails keeps polling; a successful one stops', (t) => {
    t.mock.timers.enable({ apis: ['setInterval'] });
    const { calls, reload } = counter();
    const refused = fakeChannel();
    const stop = liveReload('workspace.1.project.2', reload, () => false, () => subscription(() => true, refused.channel, () => {}));

    refused.fail();
    t.mock.timers.tick(POLL_MS);
    assert.equal(calls.n, 1);
    stop();

    const accepted = fakeChannel();
    const stop2 = liveReload('workspace.1.project.2', reload, () => false, () => subscription(() => true, accepted.channel, () => {}));
    // Before the subscription is confirmed the page still polls.
    t.mock.timers.tick(POLL_MS);
    assert.equal(calls.n, 2);
    accepted.succeed();
    t.mock.timers.tick(POLL_MS * 3);
    assert.equal(calls.n, 2);
    // A later refusal (e.g. after a reconnect) brings polling back.
    accepted.fail();
    t.mock.timers.tick(POLL_MS);
    assert.equal(calls.n, 3);
    stop2();
});
