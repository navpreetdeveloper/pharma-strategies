import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeSessionAccess } from '../../resources/js/session-access.js';

const tick = () => new Promise(resolve => setImmediate(resolve));
function fixture() {
    const attributes = new Set(), events = new Map(), documentEvents = new Map(), timers = new Map();
    const calls = [], redirects = [];
    let revoked = 0;
    let interval;
    const document = {visibilityState: 'visible', documentElement: {setAttribute: key => attributes.add(key), removeAttribute: key => attributes.delete(key)}, addEventListener: (name, callback) => documentEvents.set(name, callback)};
    const window = {PharmaStrategiesConfig: {workspaceAccessEnabled: true, userId: 7, companyId: 3}, location: {href: 'http://localhost/chat', origin: 'http://localhost', replace: url => redirects.push(url)}, addEventListener: (name, callback) => events.set(name, callback), setTimeout: callback => { const id = Symbol(); timers.set(id, callback); return id; }, clearTimeout: id => timers.delete(id), setInterval: callback => { interval = callback; return 1; }, clearInterval() {}};
    const request = (url, options) => new Promise((resolve, reject) => calls.push({url, options, resolve, reject}));
    initializeSessionAccess(document, window, request, () => revoked++);
    return {attributes, events, documentEvents, timers, calls, redirects, document, window, interval: () => interval(), revoked: () => revoked};
}
const allowed = () => ({status: 200, ok: true, json: async () => ({user_id: 7, company_id: 3})});

test('access polling is visible-only and does not overlap stalled requests', async () => {
    const f = fixture();
    f.document.visibilityState = 'hidden';
    f.interval();
    assert.equal(f.calls.length, 0);
    f.document.visibilityState = 'visible';
    f.interval(); f.interval();
    assert.equal(f.calls.length, 1);
    [...f.timers.values()][0]();
    assert.equal(f.calls[0].options.signal.aborted, true);
    f.calls[0].reject(new Error('Network timeout'));
    await tick();
    f.interval();
    assert.equal(f.calls.length, 2);
    f.calls[1].resolve(allowed());
    await tick();
    assert.equal(f.revoked(), 0);
});

test('history restoration rejects a pre-navigation response and checks the session again', async () => {
    const f = fixture();
    f.interval();
    f.events.get('pagehide')();
    assert.equal(f.calls[0].options.signal.aborted, true);
    f.events.get('pageshow')({persisted: true});
    assert.equal(f.calls.length, 2);
    f.calls[0].resolve(allowed());
    await tick();
    assert.equal(f.attributes.has('data-session-verifying'), true);
    f.calls[1].resolve({status: 401, ok: false});
    await tick();
    assert.equal(f.revoked(), 1);
    assert.equal(f.attributes.has('data-session-verifying'), true);
    assert.deepEqual(f.redirects, ['/login']);
});

test('offline restored content remains masked until a successful retry', async () => {
    const f = fixture();
    f.window.PharmaStrategiesConfig.workspaceAccessEnabled = false;
    f.events.get('pagehide')();
    f.events.get('pageshow')({persisted: true});
    assert.equal(f.calls[0].url, '/session/status');
    f.calls[0].reject(new Error('Offline'));
    await tick();
    assert.equal(f.attributes.has('data-session-verifying'), true);
    f.events.get('online')();
    f.calls[1].resolve(allowed());
    await tick();
    assert.equal(f.attributes.has('data-session-verifying'), false);
    assert.equal(f.revoked(), 0);
});

test('a different signed-in account cannot reveal another account history snapshot', async () => {
    const f = fixture();
    f.events.get('pagehide')();
    f.events.get('pageshow')({persisted: true});
    f.calls[0].resolve({status: 200, ok: true, json: async () => ({user_id: 8, company_id: 4, landing_url: '/company'})});
    await tick();
    assert.equal(f.attributes.has('data-session-verifying'), true);
    assert.equal(f.revoked(), 1);
    assert.deepEqual(f.redirects, ['http://localhost/company']);
});
