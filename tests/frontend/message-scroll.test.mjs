import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeMessageScroll } from '../../resources/js/message-scroll.js';

function fixture() {
    const box = new EventTarget();
    Object.assign(box, {scrollHeight: 1500, scrollTop: 0, clientHeight: 600});
    const input = new EventTarget();
    const window = new EventTarget();
    window.visualViewport = new EventTarget();
    const frames = new Map();
    let next = 0;
    window.requestAnimationFrame = callback => { frames.set(++next, callback); return next; };
    window.cancelAnimationFrame = id => frames.delete(id);
    const flush = () => { const pending = [...frames.values()]; frames.clear(); pending.forEach(callback => callback()); };
    const scrolling = initializeMessageScroll(box, input, window);
    flush();
    return {box, input, window, flush, scrolling, frames};
}

test('latest message remains visible as the keyboard shrinks and restores the viewport', () => {
    const f = fixture();
    f.box.scrollTop = 900;
    f.box.clientHeight = 250;
    f.window.visualViewport.dispatchEvent(new Event('resize'));
    f.flush();
    assert.equal(f.box.scrollTop, 1500);
    f.box.clientHeight = 600;
    f.window.visualViewport.dispatchEvent(new Event('resize'));
    f.flush();
    assert.equal(f.box.scrollTop, 1500);
    f.scrolling.dispose();
});

test('late attachment layout pins the latest message without interrupting history reading', () => {
    const f = fixture();
    f.box.scrollHeight = 2200;
    f.box.dispatchEvent(new Event('load'));
    f.flush();
    assert.equal(f.box.scrollTop, 2200);
    f.box.scrollTop = 100;
    f.box.dispatchEvent(new Event('scroll'));
    f.box.scrollHeight = 2600;
    f.box.dispatchEvent(new Event('load'));
    f.window.visualViewport.dispatchEvent(new Event('resize'));
    f.flush();
    assert.equal(f.box.scrollTop, 100);
    f.input.dispatchEvent(new Event('focus'));
    f.flush();
    assert.equal(f.box.scrollTop, 2600);
    f.scrolling.dispose();
});

test('loading earlier messages preserves position and cleanup removes viewport listeners', () => {
    const f = fixture();
    f.scrolling.pause();
    f.box.scrollTop = 420;
    f.box.scrollHeight = 3000;
    f.scrolling.toLatest();
    f.box.dispatchEvent(new Event('load'));
    f.flush();
    assert.equal(f.box.scrollTop, 420);
    f.scrolling.resume();
    f.scrolling.dispose();
    f.input.dispatchEvent(new Event('focus'));
    f.window.visualViewport.dispatchEvent(new Event('resize'));
    assert.equal(f.frames.size, 0);
    assert.equal(f.box.scrollTop, 420);
});
