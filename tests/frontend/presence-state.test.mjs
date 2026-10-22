import test from 'node:test';
import assert from 'node:assert/strict';
import {initializePresenceState} from '../../resources/js/presence-state.js';

const tick = () => new Promise(resolve => setImmediate(resolve));
function fixture() {
    const makeNode = id => {
        const label = {textContent: 'Offline'}, node = {dataset: {presenceUser: String(id)}, online: false, querySelector: () => label};
        node.classList = {toggle: (_, value) => node.online = value};
        node.label = label;
        return node;
    };
    let nodes = [makeNode(2)];
    const events = {}, requests = [];
    const document = {visibilityState: 'visible', querySelectorAll: () => nodes, addEventListener: (name, callback) => events[name] = callback};
    const connection = {state: 'connected', bind: (name, callback) => events[name] = callback};
    const window = {PharmaStrategiesConfig: {routes: {presence: '/presence'}}, Echo: {connector: {pusher: {connection}}},
        addEventListener: (name, callback) => events[name] = callback, setTimeout: callback => {events.timeout = callback; return 1;}, clearTimeout: () => {}, setInterval: callback => events.poll = callback,
        fetch: (url, options) => new Promise((resolve, reject) => {requests.push({url, options, resolve}); options.signal.addEventListener('abort', () => reject(new Error('aborted')));})};
    initializePresenceState(document, window);
    const emit = detail => events['pharma:presence']({detail});
    const resolve = async users => {requests.at(-1).resolve({ok: true, json: async () => ({users})}); await tick();};
    return {document, connection, events, requests, emit, resolve, node: () => nodes[0], replace: (selected = false) => {
        nodes = [makeNode(2)];
        if (selected) nodes[0].dataset = {selectedPresenceUser: '2'};
    }};
}
test('timestamps never appear in conversation list or selected chat presence labels', async () => {
    const f = fixture();
    const user = {id: 2, online: false, last_seen_at: '2026-10-11T10:00:00Z'};
    await f.resolve([user]);
    assert.equal(f.node().label.textContent, 'Offline');
    assert.equal(f.node().online, false);
    f.replace(true); f.events['pharma:presence-render']();
    assert.equal(f.node().label.textContent, 'Offline');
    f.emit({type: 'snapshot', users: [user]});
    assert.equal(f.node().label.textContent, 'Online');
    assert.equal(f.node().online, true);
    f.emit({type: 'leaving', user});
    assert.equal(f.node().label.textContent, 'Offline');
    assert.equal(f.node().online, false);
});
test('presence updates the conversation list without an open conversation and survives replaced HTML', () => {
    const f = fixture();
    f.emit({type: 'snapshot', users: [{id: 2}]});
    assert.equal(f.node().label.textContent, 'Online');
    f.replace(); f.events['pharma:presence-render']();
    assert.equal(f.node().online, true);
    assert.equal(f.node().label.textContent, 'Online');
    f.emit({type: 'leaving', user: {id: 2}});
    assert.equal(f.node().label.textContent, 'Offline');
    f.emit({type: 'joining', user: {id: 2}});
    assert.equal(f.node().online, true);
});
test('reconnection snapshot clears stale online users and wins over an older fallback request', async () => {
    const f = fixture();
    f.emit({type: 'snapshot', users: [{id: 2}]});
    f.emit({type: 'snapshot', users: []});
    await f.resolve([{id: 2, online: true}]);
    assert.equal(f.node().online, false);
});
test('fallback works when socket connects but presence authorization never completes', async () => {
    const f = fixture();
    assert.equal(f.requests[0].url, '/presence?ids[]=2');
    await f.resolve([{id: 2, online: true}]);
    assert.equal(f.node().online, true);
    f.connection.state = 'disconnected';
    f.events.poll(); await f.resolve([{id: 2, online: false}]);
    assert.equal(f.node().label.textContent, 'Offline');
});
test('hidden tabs avoid status requests and resume on return', async () => {
    const f = fixture(); await f.resolve([]);
    f.document.visibilityState = 'hidden'; await f.events.poll();
    assert.equal(f.requests.length, 1);
    f.document.visibilityState = 'visible'; f.events.visibilitychange();
    assert.equal(f.requests.length, 2);
});

test('periodic reconciliation repairs stale presence even with a connected subscribed socket', async () => {
    const f = fixture();
    await f.resolve([]);
    f.emit({type: 'snapshot', users: [{id: 2}]});
    assert.equal(f.node().online, true);
    f.events.poll();
    await f.resolve([{id: 2, online: false}]);
    assert.equal(f.node().label.textContent, 'Offline');
});

test('stalled presence requests time out so later status checks can recover', async () => {
    const f = fixture();
    f.events.timeout();
    await tick();
    f.events.poll();
    assert.equal(f.requests.length, 2);
    await f.resolve([{id: 2, online: true}]);
    assert.equal(f.node().label.textContent, 'Online');
});
