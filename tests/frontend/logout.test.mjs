import test from 'node:test';
import assert from 'node:assert/strict';
import {initializeLogout} from '../../resources/js/logout.js';

test('logout invalidates the server session before slow provider cleanup and navigation', async () => {
    let handler;
    const form = {action: 'http://localhost/logout', addEventListener: (_, fn) => handler = fn};
    const navigation = [], events = [], requests = [];
    const window = {FormData: class {}, setTimeout: () => 1, clearTimeout() {}, navigator: {serviceWorker: {getRegistration: () => new Promise(() => {})}}, location: {origin: 'http://localhost', href: 'http://localhost/chat', assign: url => navigation.push(url)}, dispatchEvent: event => events.push(event.type), HTMLFormElement: {prototype: {submit() { throw new Error('Unexpected fallback'); }}}};
    initializeLogout({querySelectorAll: () => [form]}, window, async (url, options) => { requests.push({url, options}); return {ok: true, json: async () => ({redirect: '/login'})}; });
    await handler({preventDefault() {}});
    assert.equal(requests[0].options.method, 'POST');
    assert.equal(requests[0].url, form.action);
    assert.deepEqual(events, ['pharma:logged-out']);
    assert.deepEqual(navigation, ['http://localhost/login']);
});

test('transport failures preserve the native logout fallback', async () => {
    let handler, submitted = 0;
    const form = {action: '/logout', addEventListener: (_, fn) => handler = fn};
    const window = {FormData: class {}, setTimeout: () => 1, clearTimeout() {}, HTMLFormElement: {prototype: {submit() { assert.equal(this, form); submitted++; }}}};
    initializeLogout({querySelectorAll: () => [form]}, window, async () => { throw new Error('Offline'); });
    await handler({preventDefault() {}});
    assert.equal(submitted, 1);
});
