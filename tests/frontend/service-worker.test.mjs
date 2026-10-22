import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

function fixture(clients = []) {
    const handlers = {}, notifications = [], opened = [];
    const self = {
        location: {origin: 'https://app.test'},
        addEventListener: (name, handler) => handlers[name] = handler,
        clients: {matchAll: async () => clients, openWindow: async url => opened.push(url)},
        registration: {showNotification: async (title, options) => notifications.push({title, options})},
    };
    runInNewContext(readFileSync(new URL('../../public/sw.js', import.meta.url), 'utf8'), {self, URL, Date});
    const push = async data => {
        let promise;
        handlers.push({data: {json: () => data}, waitUntil: value => promise = value});
        await promise;
    };
    const click = async url => {
        let promise;
        handlers.notificationclick({notification: {data: {url}, close() {}}, waitUntil: value => promise = value});
        await promise;
    };
    return {notifications, opened, push, click};
}
test('background push displays generic content and stable message identifiers', async () => {
    const f = fixture();
    await f.push({data: {message_id: 9, conversation_id: 3, important: true}, url: '/chat?conversation=3'});
    assert.equal(f.notifications[0].options.body, 'You have a new workplace message.');
    assert.equal(f.notifications[0].options.tag, 'conversation-3');
    assert.equal(f.notifications[0].options.data.url, '/chat?conversation=3');
});

test('new notification policy activates without waiting for every app tab to close', async () => {
    const handlers = {};
    let installed = false, claimed = false;
    const self = {addEventListener: (name, handler) => handlers[name] = handler,
        skipWaiting: async () => installed = true, clients: {claim: async () => claimed = true}};
    runInNewContext(readFileSync(new URL('../../public/sw.js', import.meta.url), 'utf8'), {self, URL, Date});
    let pending;
    const event = {waitUntil: value => pending = value};
    handlers.install(event); await pending;
    handlers.activate(event); await pending;
    assert.equal(installed, true);
    assert.equal(claimed, true);
});

test('routine messages group by conversation without repeating notification alerts', async () => {
    const f = fixture();
    await f.push({data: {message_id: 10, conversation_id: 3, important: false}});
    await f.push({data: {message_id: 11, conversation_id: 3}});
    assert.equal(f.notifications.length, 2);
    assert.equal(f.notifications[0].options.tag, 'conversation-3');
    assert.equal(f.notifications[1].options.tag, 'conversation-3');
    assert.equal(f.notifications[1].options.renotify, false);
});
test('push is suppressed only when the same conversation is visible', async () => {
    const f = fixture([{url: 'https://app.test/chat?conversation=3', visibilityState: 'visible'}]);
    await f.push({data: {conversation_id: 3}});
    assert.equal(f.notifications.length, 0);
    await f.push({data: {conversation_id: 4}});
    assert.equal(f.notifications.length, 1);
});
test('notification clicks reject external destinations and reuse a same-origin window', async () => {
    const empty = fixture();
    await empty.click('https://external.test/chat');
    assert.deepEqual(empty.opened, ['/chat']);
    const navigated = [];
    let focused = false;
    const f = fixture([{url: 'https://app.test/people', focus: async () => focused = true, navigate: async url => navigated.push(url)}]);
    await f.click('/chat?conversation=3');
    assert.equal(focused, true);
    assert.deepEqual(navigated, ['https://app.test/chat?conversation=3']);
    assert.equal(f.opened.length, 0);
});
