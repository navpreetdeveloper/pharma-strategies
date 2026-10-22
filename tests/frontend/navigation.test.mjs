import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeNavigation } from '../../resources/js/navigation.js';

function fixture() {
    const listeners = new Map();
    const target = name => ({
        name, attrs: new Map(), focused: false,
        setAttribute(key, value) { this.attrs.set(key, value); },
        getAttribute(key) { return this.attrs.get(key); },
        hasAttribute(key) { return this.attrs.has(key); },
        toggleAttribute(key, on) { on ? this.attrs.set(key, '') : this.attrs.delete(key); },
        addEventListener(type, callback) { listeners.set(name + ':' + type, callback); },
        focus() { this.focused = true; },
    });
    const header = target('header');
    const toggle = target('toggle');
    const navigation = target('nav');
    const mobile = { matches: true, addEventListener(type, cb) { listeners.set('media:' + type, cb); } };
    const properties = new Map();
    header.contains = node => node === toggle || node === navigation;
    header.getBoundingClientRect = () => ({ height: header.hasAttribute('data-nav-open') ? 180 : 68 });
    const document = {
        querySelector: () => header,
        getElementById: id => id === 'navigation-toggle' ? toggle : navigation,
        addEventListener: (type, cb) => listeners.set('document:' + type, cb),
        documentElement: { style: { setProperty: (key, value) => properties.set(key, value) } },
    };
    let resized;
    const window = {
        matchMedia: () => mobile,
        ResizeObserver: class { constructor(cb) { resized = cb; } observe() {} },
    };
    initializeNavigation(document, window);
    return { header, toggle, navigation, mobile, properties, listeners, resize: () => resized() };
}

test('mobile navigation opens and Escape closes it and restores keyboard focus', () => {
    const ui = fixture();
    assert.equal(ui.toggle.getAttribute('aria-expanded'), 'false');
    ui.listeners.get('toggle:click')();
    assert.equal(ui.toggle.getAttribute('aria-expanded'), 'true');
    ui.listeners.get('document:keydown')({ key: 'Escape' });
    assert.equal(ui.header.hasAttribute('data-nav-open'), false);
    assert.equal(ui.toggle.focused, true);
});

test('clicks inside the header preserve the menu and outside clicks dismiss it', () => {
    const ui = fixture();
    ui.listeners.get('toggle:click')();
    ui.listeners.get('document:click')({ target: ui.toggle });
    assert.equal(ui.header.hasAttribute('data-nav-open'), true);
    ui.listeners.get('document:click')({ target: {} });
    assert.equal(ui.header.hasAttribute('data-nav-open'), false);
});

test('mobile links and breakpoint changes close the navigation disclosure', () => {
    const ui = fixture();
    ui.listeners.get('toggle:click')();
    ui.listeners.get('nav:click')({ target: { closest: () => ({}) } });
    assert.equal(ui.toggle.getAttribute('aria-expanded'), 'false');
    ui.listeners.get('toggle:click')();
    ui.mobile.matches = false;
    ui.listeners.get('media:change')();
    assert.equal(ui.header.hasAttribute('data-nav-open'), false);
});

test('chat viewport measurement follows the actual header size', () => {
    const ui = fixture();
    assert.equal(ui.properties.get('--nc-header-height'), '68px');
    ui.listeners.get('toggle:click')();
    ui.resize();
    assert.equal(ui.properties.get('--nc-header-height'), '180px');
    ui.listeners.get('toggle:click')();
    ui.resize();
    assert.equal(ui.properties.get('--nc-header-height'), '68px');
});

test('public pages without a menu need no navigation enhancement', () => {
    assert.doesNotThrow(() => initializeNavigation({
        querySelector: () => ({}), getElementById: () => null,
    }, {}));
});
