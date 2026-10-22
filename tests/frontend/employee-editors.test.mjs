import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeEmployeeEditors } from '../../resources/js/employee-editors.js';

function fixture(reopen = false) {
    const handlers = new Map(), documentEvents = new Map(), viewportEvents = new Map();
    const values = new Map(), classes = new Set();
    let opened = false, focus = 0;
    const trigger = {dataset: {employeeEditor: 'employee-editor-5'}, focus: () => focus++};
    const dialog = {
        style: {setProperty: (key, value) => values.set(key, value)},
        showModal: () => opened = true,
        close: () => { opened = false; handlers.get('close')(); },
        addEventListener: (name, fn) => handlers.set(name, fn),
        getBoundingClientRect: () => ({left: 20, right: 600, top: 30, bottom: 700}),
    };
    const document = {
        querySelector: selector => selector === '.nc-employee-dialog' || (reopen && selector === '[data-employee-editor-reopen]') ? dialog : null,
        querySelectorAll: () => [dialog],
        getElementById: id => id === 'employee-editor-5' ? dialog : null,
        addEventListener: (name, fn) => documentEvents.set(name, fn),
        body: {classList: {add: name => classes.add(name), remove: name => classes.delete(name)}},
    };
    const window = {innerHeight: 900, visualViewport: {height: 850, addEventListener: (name, fn) => viewportEvents.set(name, fn)}, addEventListener() {}};
    initializeEmployeeEditors(document, window);
    const click = selector => documentEvents.get('click')({target: {closest: name => name === selector ? (selector === '[data-employee-editor]' ? trigger : {}) : null}});
    return {click, handlers, viewportEvents, window, values, classes, dialog, opened: () => opened, focus: () => focus};
}

test('Manage opens a modal and closing restores focus and page scrolling', () => {
    const f = fixture();
    f.click('[data-employee-editor]');
    assert.equal(f.opened(), true);
    assert.equal(f.classes.has('nc-employee-editor-open'), true);
    f.click('[data-employee-editor-close]');
    assert.equal(f.opened(), false);
    assert.equal(f.focus(), 1);
    assert.equal(f.classes.size, 0);
});

test('editor height follows the visible viewport while the keyboard is open', () => {
    const f = fixture();
    f.click('[data-employee-editor]');
    f.window.visualViewport.height = 400;
    f.viewportEvents.get('resize')();
    assert.equal(f.values.get('--nc-editor-height'), '400px');
    f.dialog.close();
});

test('only backdrop clicks close the editor', () => {
    const f = fixture();
    f.click('[data-employee-editor]');
    f.handlers.get('click')({target: f.dialog, clientX: 100, clientY: 100});
    assert.equal(f.opened(), true);
    f.handlers.get('click')({target: f.dialog, clientX: 1, clientY: 1});
    assert.equal(f.opened(), false);
});

test('validation errors reopen the affected employee dialog', () => {
    const f = fixture(true);
    assert.equal(f.opened(), true);
    assert.equal(f.values.get('--nc-editor-height'), '850px');
    f.dialog.close();
});
