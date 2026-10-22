import test from 'node:test';
import assert from 'node:assert/strict';
import { createMessageReceiver } from '../../resources/js/message-receiver.js';

test('duplicate simultaneous events share a read but later events reauthorize fresh content', async () => {
    const calls = [];
    const receive = createMessageReceiver({fetch: (url, options) => new Promise(resolve => calls.push({url, options, resolve}))}, () => {}, () => false);
    const a = receive(8), b = receive(8);
    assert.equal(calls.length, 1);
    assert.equal(calls[0].options.cache, 'no-store');
    calls[0].resolve({ok: true, json: async () => ({message: {id: 8, body: 'First'}})});
    assert.equal((await a).body, 'First'); assert.equal((await b).body, 'First');
    const later = receive(8);
    assert.equal(calls.length, 2);
    calls[1].resolve({ok: true, json: async () => ({message: {id: 8, body: 'Edited'}})});
    assert.equal((await later).body, 'Edited');
});

test('revocation prevents private content from rendering after a pending read', async () => {
    let resolve;
    let revoked = false;
    const receive = createMessageReceiver({fetch: () => new Promise(callback => resolve = callback)}, () => revoked = true, () => revoked);
    const request = receive(9);
    revoked = true;
    resolve({ok: true, json: async () => ({message: {body: 'Private'}})});
    assert.equal(await request, null);
    assert.equal(await receive(9), null);
});

test('denied reads revoke access and failed transport reads can be retried', async () => {
    let calls = 0, denied = 0;
    const receive = createMessageReceiver({fetch: async () => { if (++calls === 1) throw new Error('Offline'); return {ok: false, status: 403}; }}, () => denied++, () => false);
    await assert.rejects(receive(10), /Offline/);
    assert.equal(await receive(10), null);
    assert.equal(denied, 1);
});

test('a stalled live read is aborted and a later event starts a fresh request', async () => {
    let calls = 0;
    const receive = createMessageReceiver({fetch: (url, {signal}) => {
        if (++calls > 1) return Promise.resolve({ok: true, json: async () => ({message: {id: 10}})});
        return new Promise((resolve, reject) => signal.addEventListener('abort', () => reject(new DOMException('Timed out', 'AbortError')), {once: true}));
    }}, () => assert.fail('Transport timeout must not revoke access'), () => false, 10);
    await assert.rejects(receive(10), {name: 'AbortError'});
    assert.equal((await receive(10)).id, 10);
    assert.equal(calls, 2);
});
