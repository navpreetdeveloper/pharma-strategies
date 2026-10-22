import test from 'node:test';
import assert from 'node:assert/strict';
import { handleIncomingMessage } from '../../resources/js/live-message.js';

test('incoming open-thread content renders before the sidebar request starts', async () => {
    const steps = [];
    let finish;
    const task = handleIncomingMessage({data: {conversation_id: 4, message_id: 8}}, {
        conversationId: 4,
        receive: id => { steps.push(`read-${id}`); return new Promise(resolve => finish = resolve); },
        deliver: (id, message) => steps.push(`render-${id}-${message.id}`),
        refresh: () => steps.push('sidebar'),
    });
    assert.deepEqual(steps, ['read-8']);
    finish({id: 8});
    await task;
    assert.deepEqual(steps, ['read-8', 'render-4-8', 'sidebar']);
});

test('an unopened conversation refreshes unread counts without fetching its private body', async () => {
    let refreshed = false;
    await handleIncomingMessage({data: {conversation_id: 5, message_id: 9}}, {
        conversationId: 4, receive: () => assert.fail('Unexpected body read'),
        deliver: () => assert.fail('Unexpected render'), refresh: () => refreshed = true,
    });
    assert.equal(refreshed, true);
});

test('failed live reads still refresh the list and leave recovery available', async () => {
    let refreshed = false;
    await handleIncomingMessage({data: {conversation_id: 4, message_id: 9}}, {
        conversationId: 4, receive: async () => { throw new Error('Offline'); },
        deliver: () => assert.fail('Unexpected render'), refresh: () => refreshed = true,
    });
    assert.equal(refreshed, true);
});
