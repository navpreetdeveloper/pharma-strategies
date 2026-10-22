import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeChatViewport } from '../../resources/js/chat-viewport.js';

test('mobile chat height follows the visible viewport when the keyboard opens', () => {
    const values = new Map();
    const events = new Map();
    const document = {querySelector: () => ({}), documentElement: {style: {setProperty: (key, value) => values.set(key, value)}}};
    const window = {innerHeight: 844, addEventListener: () => {}, visualViewport: {height: 844, addEventListener: (event, handler) => events.set(event, handler)}};
    initializeChatViewport(document, window);
    assert.equal(values.get('--nc-viewport-height'), '844px');
    window.visualViewport.height = 412;
    window.visualViewport.offsetTop = 90;
    events.get('resize')();
    assert.equal(values.get('--nc-viewport-height'), '412px');
    assert.equal(values.get('--nc-viewport-top'), '90px');
    window.visualViewport.offsetTop = 34;
    events.get('scroll')();
    assert.equal(values.get('--nc-viewport-top'), '34px');
    window.visualViewport.height = 844;
    events.get('resize')();
    assert.equal(values.get('--nc-viewport-height'), '844px');
});

test('browsers without VisualViewport use the window height', () => {
    let value;
    initializeChatViewport({querySelector: () => ({}), documentElement: {style: {setProperty: (key, next) => value = next}}}, {innerHeight: 768, addEventListener: () => {}});
    assert.equal(value, '768px');
});

test('non-chat pages do not install viewport listeners', () => {
    initializeChatViewport({querySelector: () => null}, {addEventListener: () => assert.fail('Unexpected viewport listener')});
});
