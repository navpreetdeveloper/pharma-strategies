import test from 'node:test';
import assert from 'node:assert/strict';
import {createPresenceStore} from '../../resources/js/state/presence-store.js';

test('a new presence snapshot marks departed users offline and copies external state', () => {
    const store = createPresenceStore();
    const original = {online: true, last_seen_at: '2026-10-11T10:00:00Z'};
    store.set(1, original);
    original.online = false;
    assert.equal(store.get(1).online, true);
    store.snapshot([{id: '2'}]);
    assert.equal(store.get('1').online, false);
    assert.equal(store.get(1).last_seen_at, original.last_seen_at);
    assert.equal(store.get(2).online, true);
});
