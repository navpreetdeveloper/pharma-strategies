import test from 'node:test';
import assert from 'node:assert/strict';
import {initializeChatImageViewer} from '../../resources/js/chat-image-viewer.js';

function fixture() {
    const created = [], events = {}, windowEvents = {};
    const createElement = tag => {
        const node = {tag, children: [], events: {}, attributes: {},
            append(...children) { this.children.push(...children); },
            setAttribute(key, value) { this.attributes[key] = value; },
            removeAttribute(key) { delete this[key]; },
            addEventListener(key, callback) { this.events[key] = callback; },
            showModal() { this.open = true; }, close() { this.open = false; this.events.close(); },
            focus() { this.focused = true; },
        };
        created.push(node); return node;
    };
    const document = {createElement, body: {append() {}}, addEventListener: (name, callback) => events[name] = callback};
    const window = {location: {href: 'http://localhost/chat', origin: 'http://localhost'}, addEventListener: (name, callback) => windowEvents[name] = callback};
    initializeChatImageViewer(document, window);
    const [dialog, toolbar, name, download, close, stage, image, error] = created;
    const click = (href = '/attachments/4/preview', extra = {}) => {
        const link = {href, querySelector: () => ({alt: '<private photo>.png'}), closest: () => ({querySelector: () => ({href: '/attachments/4/download'})})};
        let prevented = false;
        events.click({target: {closest: () => link}, button: 0, preventDefault() { prevented = true; }, ...extra});
        return prevented;
    };
    return {dialog, name, download, close, image, error, click, windowEvents};
}
test('shared images open in a modal with safe text, download and focused close control', () => {
    const f = fixture();
    assert.equal(f.click(), true);
    assert.equal(f.dialog.open, true);
    assert.equal(f.image.src, 'http://localhost/attachments/4/preview');
    assert.equal(f.name.textContent, '<private photo>.png');
    assert.equal(f.download.href, '/attachments/4/download');
    assert.equal(f.close.focused, true);
    f.close.events.click();
    assert.equal(f.dialog.open, false);
    assert.equal(f.image.src, undefined);
});
test('delegation supports later images, handles failures and dismisses on chat navigation', () => {
    const f = fixture();
    f.click('/attachments/9/preview');
    f.image.events.error(); assert.equal(f.error.hidden, false);
    f.windowEvents['pharma:close-image']();
    f.click('/attachments/10/preview');
    assert.equal(f.image.hidden, false);
    assert.equal(f.error.hidden, true);
    f.windowEvents.popstate(); assert.equal(f.dialog.open, false);
});
test('foreign links and modified clicks retain browser behavior', () => {
    const f = fixture();
    assert.equal(f.click('https://another.example/image'), false);
    assert.equal(f.click('/attachments/4/preview', {ctrlKey: true}), false);
    assert.equal(f.dialog.open, undefined);
});
