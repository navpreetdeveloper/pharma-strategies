import test from 'node:test';
import assert from 'node:assert/strict';
import {initializeChatRecovery} from '../../resources/js/chat-recovery.js';

function fixture(state = 'disconnected') {
    const events = {}, calls = [], emitted = [];
    const connection = {state, bind: (name, callback) => events[name] = callback};
    const node = {textContent: JSON.stringify({conversationId: 3, recoveryCursor: 7, updatesUrl: '/conversations/3/updates'})};
    const document = {visibilityState: 'visible', querySelector: () => node, querySelectorAll: () => [{dataset: {messageId: 7}}], addEventListener: (name, callback) => events[name] = callback};
    const window = {Echo: {connector: {pusher: {connection}}}, addEventListener: (name, callback) => events[name] = callback,
        setTimeout: callback => {events.timeout = callback; return 1;}, clearTimeout: () => {}, setInterval: callback => events.poll = callback, fetch: async (url, options) => {calls.push({url, options}); return {ok: true, json: async () => ({messages: [{id: 8}], cursor: 8, has_more: false})};}, dispatchEvent: event => emitted.push(event)};
    initializeChatRecovery(document, window);
    return {events, calls, emitted, document, connection, window};
}
test('disconnected chats fetch only newer messages and refresh the conversation list', async () => {
    const f = fixture();
    await f.events.poll();
    assert.equal(f.calls[0].url, '/conversations/3/updates?after=7&sidebar=1&conversations_page=1&visible%5B%5D=7');
    assert.equal(f.calls[0].options.cache, 'no-store');
    assert.equal(f.emitted[0].type, 'pharma:message');
    assert.equal(f.emitted[0].detail.conversationId, 3);
    assert.equal(f.emitted.at(-1).type, 'pharma:chat-list-refresh');
});
test('connected transport still reconciles missed events and catches up after reconnect', async () => {
    const f = fixture('connected');
    await f.events.poll(); assert.equal(f.calls.length, 1);
    await f.events.connected(); assert.equal(f.calls.length, 2);
});
test('background tabs do no recovery work and returning catches up', async () => {
    const f = fixture();
    f.document.visibilityState = 'hidden'; await f.events.poll();
    assert.equal(f.calls.length, 0);
    f.document.visibilityState = 'visible'; await f.events.visibilitychange();
    assert.equal(f.calls.length, 1);
});

test('a stalled recovery request times out and does not block subsequent checks', async () => {
    const f = fixture('connected');
    const fetch = f.window.fetch;
    f.window.fetch = (_, options) => new Promise((resolve, reject) => {
        options.signal.addEventListener('abort', () => reject(new Error('aborted')));
    });
    const stalled = f.events.poll();
    f.events.timeout();
    await stalled;
    f.window.fetch = fetch;
    await f.events.poll();
    assert.equal(f.calls.length, 1);
    assert.equal(f.emitted[0].detail.message.id, 8);
});

test('combined recovery renders messages before sidebar and avoids a second refresh request', async () => {
    const f = fixture();
    f.window.location = {href: 'http://localhost/chat?conversation=3&conversations_page=2'};
    f.window.fetch = async url => {f.calls.push({url});return {ok:true,json:async()=>({messages:[{id:8}],sidebar:'<div data-conversation-results></div>'})};};
    await f.events.poll();
    assert.equal(f.calls.length, 1);
    assert.match(f.calls[0].url, /conversations_page=2/);
    assert.deepEqual(f.emitted.map(e=>e.type), ['pharma:message','pharma:message-reconcile','pharma:recovery-complete','pharma:chat-sidebar']);
    assert.equal(f.emitted.at(-1).detail.url, f.window.location.href);
});


test('live later IDs cannot advance the independent catch-up cursor', async () => {
    const f = fixture();
    f.document.querySelectorAll = () => [7, 12].map(id => ({dataset: {messageId: id}}));
    await f.events.poll();
    await f.events.poll();
    assert.equal(new URL(f.calls[0].url, 'http://localhost').searchParams.get('after'), '7');
    assert.equal(new URL(f.calls[1].url, 'http://localhost').searchParams.get('after'), '8');
});

test('catch-up drains pages and reconciles missed edits, unsends and pruned rows', async () => {
    const f = fixture();
    let page = 0;
    f.window.fetch = async url => {
        f.calls.push({url});
        page++;
        return {ok: true, json: async () => ({messages: [{id: page === 1 ? 8 : 9}], cursor: page === 1 ? 8 : 9, has_more: page === 1, reconciled: [{id: 7, body: null, deleted_at: 'now'}], removed: [6]})};
    };
    await f.events.connected();
    assert.equal(f.calls.length, 2);
    assert.equal(new URL(f.calls[1].url, 'http://localhost').searchParams.get('after'), '8');
    const repair = f.emitted.find(event => event.type === 'pharma:message-reconcile');
    assert.deepEqual(repair.detail.removed, [6]);
    assert.equal(repair.detail.messages[0].deleted_at, 'now');
});

test('a response for a replaced chat view is discarded without advancing its cursor', async () => {
    const f = fixture();
    const node = f.document.querySelector();
    f.window.fetch = async () => {
        f.document.querySelector = () => ({textContent: node.textContent});
        return {ok: true, json: async () => ({messages: [{id: 8}], cursor: 8})};
    };
    await f.events.poll();
    assert.equal(f.emitted.length, 0);
});


test('active thread notifications catch up immediately instead of waiting for the poll', async () => {
    const f=fixture();
    await f.events['pharma:chat-recover']();
    assert.equal(f.calls.length,1);
    assert.equal(f.emitted.some(event=>event.type==='pharma:recovery-complete'),true);
});

test('a burst during catch-up schedules one follow-up without overlapping requests', async () => {
    const f=fixture();let release;let count=0;
    f.window.fetch=async url=>{f.calls.push({url});count++;if(count===1)await new Promise(resolve=>release=resolve);return {ok:true,json:async()=>({messages:[],cursor:8,has_more:false})};};
    const first=f.events['pharma:chat-recover']();
    await f.events['pharma:chat-recover']();await f.events['pharma:chat-recover']();
    assert.equal(f.calls.length,1);release();await first;await new Promise(setImmediate);
    assert.equal(f.calls.length,2);
});
