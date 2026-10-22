import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeChatNavigation, isChatUrl } from '../../resources/js/chat-navigation.js';

const tick = () => new Promise(resolve => setImmediate(resolve));
function fixture() {
    const config = {textContent: '{"conversationId":null}'};
    let current;
    const view = label => ({
        label, attributes: new Map(),
        querySelector: selector => selector === '[data-chat-config]' ? config : null,
        setAttribute(key, value) { this.attributes.set(key, value); },
        removeAttribute(key) { this.attributes.delete(key); },
        replaceWith(next) { current = next; },
        prepend(notice) { this.notice = notice; },
    });
    current = view('initial');
    const documentEvents = new Map(), windowEvents = new Map();
    let sending = false;
    const sidebarRenders = [];
    const panel = {replaceChildren: (...nodes) => sidebarRenders.push(nodes), querySelectorAll: () => []};
    const document = {
        querySelector: selector => selector === '[data-chat-view]' ? current : selector === '[data-chat-config]' ? config : selector === '[data-conversation-results]' ? panel : selector === '[data-chat-navigation-error]' ? current.notice : null,
        getElementById: id => id === 'send-button' ? {disabled: sending} : null,
        addEventListener: (event, handler) => documentEvents.set(event, handler),
        createElement: () => ({dataset: {}, setAttribute() {}}),
        importNode: node => node,
    };
    const calls = [], assigned = [], history = [];
    const timers = new Map();
    const window = {
        location: {href: 'http://localhost/chat', origin: 'http://localhost', pathname: '/chat', assign: url => assigned.push(url)},
        history: {pushState: (_, __, url) => history.push(url), replaceState: (_, __, url) => history.push(url)},
        addEventListener: (event, handler) => windowEvents.set(event, handler),
        scrollTo() {},
        setTimeout: callback => { const id = Symbol(); timers.set(id, callback); return id; },
        clearTimeout: id => timers.delete(id),
        DOMParser: class { parseFromString(label) { const next = view(label); return {title: label, querySelector: selector => selector === '[data-conversation-results]' ? {childNodes: [label]} : next}; } },
        FormData: class { constructor(form) { this.form = form; } },
        fetch: (url, options) => new Promise((resolve, reject) => calls.push({url, options, resolve, reject})),
    };
    initializeChatNavigation(document, window);
    const click = (url, extra = {}) => {
        let prevented = false;
        documentEvents.get('click')({target: {closest: () => ({href: url, hasAttribute: () => false, setAttribute() {}, removeAttribute() {}})}, button: 0, preventDefault: () => prevented = true, ...extra});
        return prevented;
    };
    const response = (label, url = 'http://localhost/chat?conversation=1', status = 200) => ({url, ok: status === 200, status, text: async () => label});
    return {calls, assigned, history, sidebarRenders, timers, click, response, current: () => current, events: windowEvents, documentEvents, setSending: value => sending = value};
}

test('a stalled sidebar request times out and later updates can still complete', async () => {
    const f = fixture();
    const refresh = f.events.get('pharma:chat-list-refresh');
    refresh();
    [...f.timers.values()][0]();
    assert.equal(f.calls[0].options.signal.aborted, true);
    f.calls[0].reject(Object.assign(new Error('Timed out'), {name: 'AbortError'}));
    await tick();
    assert.equal(f.timers.size, 0);
    refresh();
    f.calls[1].resolve(f.response('fresh sidebar', 'http://localhost/chat'));
    await tick();
    assert.deepEqual(f.sidebarRenders, [['fresh sidebar']]);
    assert.equal(f.timers.size, 0);
});

test('message bursts finish the current sidebar request and coalesce a single follow-up', async () => {
    const f = fixture();
    const refresh = f.events.get('pharma:chat-list-refresh');
    refresh();
    refresh();
    refresh();
    assert.equal(f.calls.length, 1);
    assert.equal(f.calls[0].options.signal.aborted, false);
    f.calls[0].resolve(f.response('first sidebar', 'http://localhost/chat'));
    await tick();
    assert.deepEqual(f.sidebarRenders, [['first sidebar']]);
    assert.equal(f.calls.length, 2);
    f.calls[1].resolve(f.response('latest sidebar', 'http://localhost/chat'));
    await tick();
    assert.equal(f.calls.length, 2);
    assert.deepEqual(f.sidebarRenders[1], ['latest sidebar']);
});

test('navigation cancels stale sidebar work without rendering or replaying it', async () => {
    const f = fixture();
    const refresh = f.events.get('pharma:chat-list-refresh');
    refresh();
    refresh();
    f.click('http://localhost/chat?conversation=1');
    assert.equal(f.calls[0].options.signal.aborted, true);
    f.calls[0].resolve(f.response('stale sidebar', 'http://localhost/chat'));
    f.calls[1].resolve(f.response('new conversation'));
    await tick();
    assert.deepEqual(f.sidebarRenders, []);
    assert.equal(f.calls.length, 2);
    assert.equal(f.current().label, 'new conversation');
});

test('a failed sidebar request does not block the next refresh', async () => {
    const f = fixture();
    const refresh = f.events.get('pharma:chat-list-refresh');
    refresh();
    f.calls[0].resolve(f.response('unavailable', 'http://localhost/chat', 503));
    await tick();
    refresh();
    assert.equal(f.calls.length, 2);
    f.calls[1].resolve(f.response('recovered sidebar', 'http://localhost/chat'));
    await tick();
    assert.deepEqual(f.sidebarRenders, [['recovered sidebar']]);
});

test('only same-origin chat links qualify for partial navigation', () => {
    const base = 'https://example.test/chat';
    assert.equal(isChatUrl('/chat?conversation=4', base), true);
    assert.equal(isChatUrl('https://other.test/chat', base), false);
    assert.equal(isChatUrl('/logout', base), false);
    assert.equal(isChatUrl('/chat#main-content', base), false);
});

test('conversation switching replaces only the chat view and uses fresh authenticated requests', async () => {
    const f = fixture();
    assert.equal(f.click('http://localhost/chat?conversation=1'), true);
    assert.equal(f.current().label, 'initial');
    assert.equal(f.current().attributes.get('aria-busy'), 'true');
    assert.equal(f.calls[0].options.cache, 'no-store');
    assert.equal(f.calls[0].options.credentials, 'same-origin');
    f.calls[0].resolve(f.response('first'));
    await tick();
    assert.equal(f.current().label, 'first');
    assert.deepEqual(f.history, ['http://localhost/chat?conversation=1']);
    assert.deepEqual(f.assigned, []);
});

test('rapid conversation clicks abort the old request and discard late responses', async () => {
    const f = fixture();
    f.click('http://localhost/chat?conversation=1');
    f.click('http://localhost/chat?conversation=2');
    assert.equal(f.calls[0].options.signal.aborted, true);
    f.calls[1].resolve(f.response('second', 'http://localhost/chat?conversation=2'));
    await tick();
    f.calls[0].resolve(f.response('stale'));
    await tick();
    assert.equal(f.current().label, 'second');
    assert.deepEqual(f.history, ['http://localhost/chat?conversation=2']);
});

test('expired sessions navigate to login and forbidden responses keep the current view', async () => {
    const f = fixture();
    f.click('http://localhost/chat?conversation=1');
    f.calls[0].resolve(f.response('login', 'http://localhost/login'));
    await tick();
    assert.deepEqual(f.assigned, ['http://localhost/login']);
    assert.equal(f.current().label, 'initial');
    f.click('http://localhost/chat?conversation=2');
    f.calls[1].resolve(f.response('forbidden', 'http://localhost/chat?conversation=2', 403));
    await tick();
    assert.equal(f.current().label, 'initial');
    assert.match(f.current().notice.textContent, /no longer have access/);
});

test('modified clicks retain browser behavior and sending prevents accidental navigation', () => {
    const f = fixture();
    assert.equal(f.click('http://localhost/chat?conversation=1', {ctrlKey: true}), false);
    assert.equal(f.calls.length, 0);
    f.setSending(true);
    assert.equal(f.click('http://localhost/chat?conversation=1'), true);
    assert.equal(f.calls.length, 0);
    assert.match(f.current().notice.textContent, /finish sending/);
});

test('starting a chat submits the original form including its CSRF field without reloading', async () => {
    const f = fixture();
    const form = {action: 'http://localhost/conversations', csrf: 'existing-token'};
    f.documentEvents.get('submit')({target: {closest: () => form}, preventDefault() {}});
    assert.equal(f.calls[0].options.method, 'POST');
    assert.equal(f.calls[0].options.body.form, form);
    f.calls[0].resolve(f.response('draft'));
    await tick();
    assert.equal(f.current().label, 'draft');
});
