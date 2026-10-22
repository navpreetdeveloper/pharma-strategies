import test from 'node:test';
import assert from 'node:assert/strict';
import {initializeChatReadState} from '../../resources/js/chat-read-state.js';

function fixture() {
    let ids = [1], succeed = true;
    const events = {}, requests = [], refreshed = [];
    const document = {visibilityState: 'visible', querySelector: () => ({content: 'csrf'}), addEventListener: (name, callback) => events[name] = callback};
    const box = {scrollHeight: 100, scrollTop: 0, clientHeight: 100, querySelectorAll: () => ids.map(id => ({dataset: {messageId: id}})), addEventListener: (name, callback) => events[name] = callback};
    const window = {fetch: async (url, options) => { requests.push({url, options}); return {ok: succeed}; }, dispatchEvent: event => refreshed.push(event.type)};
    const lifecycle = new AbortController();
    const mark = initializeChatReadState(document, window, box, {readUrl: '/conversations/3/read'}, lifecycle.signal);
    return {document, box, requests, refreshed, events, mark, lifecycle, ids: value => ids = value, succeed: value => succeed = value};
}
test('visible incoming messages acknowledge their ID once and refresh unread counts', async () => {
    const f = fixture();
    f.ids([1, 2]);
    await f.mark(); await f.mark();
    assert.equal(f.requests.length, 1);
    assert.deepEqual(JSON.parse(f.requests[0].options.body), {last_message_id: 2});
    assert.deepEqual(f.refreshed, ['pharma:chat-list-refresh']);
});
test('hidden tabs and readers of older messages keep new messages unread', async () => {
    const f = fixture();
    f.ids([1, 2]);
    f.document.visibilityState = 'hidden'; await f.mark();
    f.document.visibilityState = 'visible'; f.box.scrollHeight = 500; await f.mark();
    assert.equal(f.requests.length, 0);
    f.box.scrollTop = 400; await f.events.scroll();
    assert.equal(f.requests.length, 1);
});
test('failed acknowledgements retry and closed chats stop requests', async () => {
    const f = fixture();
    f.ids([1, 2]); f.succeed(false); await f.mark();
    assert.equal(f.refreshed.length, 0);
    f.succeed(true); await f.mark();
    assert.equal(f.requests.length, 2);
    f.lifecycle.abort(); f.ids([1, 2, 3]); await f.mark();
    assert.equal(f.requests.length, 2);
});
