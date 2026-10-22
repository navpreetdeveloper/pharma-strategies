import test from 'node:test';
import assert from 'node:assert/strict';
import {initializePresenceHeartbeat} from '../../resources/js/presence-heartbeat.js';

const tick = () => new Promise(resolve => setImmediate(resolve));
function fixture(disabled = false) {
    const events = {}, calls = [];
    const document = {visibilityState: 'visible', addEventListener: (name, callback) => events[name] = callback};
    const window = {
        PharmaStrategiesConfig: {companyId: 1, presenceEnabled: !disabled, routes: {heartbeat: '/presence/heartbeat'}},
        setTimeout: callback => {events.timeout = callback; return 1;}, clearTimeout: () => {},
        setInterval: (callback, duration) => {events.poll = callback; events.interval = duration;},
        addEventListener: (name, callback) => events[name] = callback,
    };
    const request = (url, options) => new Promise((resolve, reject) => {
        calls.push({url, options, resolve});
        options.signal.addEventListener('abort', () => reject(new Error('aborted')));
    });
    initializePresenceHeartbeat(document, window, request);
    return {document, events, calls};
}
test('heartbeats run without Echo and do not overlap requests', async () => {
    const f = fixture();
    assert.equal(f.calls[0].url, '/presence/heartbeat');
    assert.equal(f.calls[0].options.method, 'POST');
    assert.equal(f.events.interval, 20000);
    await f.events.poll();
    assert.equal(f.calls.length, 1);
    f.calls[0].resolve(); await tick();
    f.events.poll();
    assert.equal(f.calls.length, 2);
});
test('hidden tabs stop heartbeats and foreground or restored network retries timed out requests', async () => {
    const f = fixture();
    f.events.timeout(); await tick();
    f.document.visibilityState = 'hidden'; await f.events.poll();
    assert.equal(f.calls.length, 1);
    f.document.visibilityState = 'visible'; f.events.visibilitychange();
    assert.equal(f.calls.length, 2);
    f.calls[1].resolve(); await tick();
    f.events.online(); assert.equal(f.calls.length, 3);
});
test('platform accounts with presence disabled send no heartbeats', () => {
    assert.equal(fixture(true).calls.length, 0);
});
