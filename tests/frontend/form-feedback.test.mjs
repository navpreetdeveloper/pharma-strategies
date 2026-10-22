import test from 'node:test';
import assert from 'node:assert/strict';
import { passwordFeedback, initializeFormFeedback } from '../../resources/js/form-feedback.js';

function fixture(serverErrors = null, emailMode = false) {
    const nodes = [], attributes = new Map(), listeners = new Map();
    const field = (name, value) => {
        const attrs = new Map(), events = new Map();
        return {name, value, willValidate: true, focused: false, custom: '', parentElement: {querySelector: () => null},
            get validity() { return {valid: !!this.value && !this.custom}; },
            get validationMessage() { return this.custom || 'Complete this field.'; },
            getAttribute: name => attrs.get(name), setAttribute: (name, value) => attrs.set(name, value), removeAttribute: name => attrs.delete(name),
            insertAdjacentElement: (_, node) => nodes.push(node), setCustomValidity(value) { this.custom = value; },
            addEventListener: (name, fn) => events.set(name, [...(events.get(name) || []), fn]),
            input(value) { this.value = value; for (const fn of events.get('input') || []) fn(); },
            focus() { this.focused = true; }, attrs,
        };
    };
    const password = field(emailMode ? 'email' : 'password', ''), confirmation = field('password_confirmation', '');
    const form = {method: 'post', elements: emailMode ? [password] : [password, confirmation], matches: () => false, prepend: node => nodes.push(node)};
    const scope = {querySelectorAll: () => form.elements, querySelector: () => form, prepend: node => nodes.push(node)};
    const document = {
        querySelectorAll: selector => selector === 'form' ? [form] : emailMode ? [] : [password, confirmation],
        querySelector: selector => selector === '[data-validation-errors]' ? (serverErrors ? {textContent: JSON.stringify(serverErrors)} : null) : scope,
        createElement: () => ({hidden: false, classList: {toggle() {}}, setAttribute: (name, value) => attributes.set(name, value)}),
        addEventListener: (_, fn) => listeners.set('submit', fn),
    };
    initializeFormFeedback(document);
    const submit = () => {
        const event = {target: form, prevented: false, stopped: false, preventDefault() { this.prevented = true; }, stopImmediatePropagation() { this.stopped = true; }};
        listeners.get('submit')(event);
        return event;
    };
    return {password, confirmation, form, nodes, submit};
}

test('password guidance identifies missing requirements and accepts eight characters', () => {
    assert.match(passwordFeedback('newpass1'), /uppercase/);
    assert.match(passwordFeedback('NEWPASS1'), /lowercase/);
    assert.match(passwordFeedback('NewPasss'), /number/);
    assert.match(passwordFeedback('NewPas1'), /8 characters/);
    assert.equal(passwordFeedback('NewPass1'), '');
});

test('invalid passwords stay inline and stop submission before confirmation', () => {
    const f = fixture();
    f.password.input('newpass1'); f.confirmation.input('newpass1');
    const event = f.submit();
    assert.equal(f.form.noValidate, true);
    assert.equal(event.prevented, true); assert.equal(event.stopped, true);
    assert.equal(f.password.focused, true);
    assert.equal(f.password.attrs.get('aria-invalid'), 'true');
    assert.match(f.nodes[0].textContent, /uppercase/);
    assert.equal(f.nodes.filter(node => node.className === 'nc-field-error').length, 0);
    f.password.input('NewPass1'); f.confirmation.input('NewPass1');
    assert.equal(f.submit().prevented, false);
    assert.equal(f.password.attrs.has('aria-invalid'), false);
});

test('confirmation mismatch is linked to the field and clears after correction', () => {
    const f = fixture();
    f.password.input('NewPass1'); f.confirmation.input('Different1');
    assert.equal(f.submit().prevented, true);
    assert.equal(f.nodes.find(node => node.className === 'nc-field-error').textContent, 'Passwords do not match.');
    assert.match(f.confirmation.attrs.get('aria-describedby'), /field-feedback-/);
    f.confirmation.input('NewPass1');
    assert.equal(f.submit().prevented, false);
});

test('server password errors remain plain text underneath the affected input', () => {
    const f = fixture({password: ['<script>server validation</script>']});
    assert.equal(f.nodes[0].textContent, '<script>server validation</script>');
    assert.equal(f.password.attrs.get('aria-invalid'), 'true');
    assert.equal(f.nodes[0].innerHTML, undefined);
});

test('malformed email is rejected with a concise inline email message', () => {
    const f = fixture(null, true);
    f.password.type = 'email';
    f.password.value = 'invalid-email';
    Object.defineProperty(f.password, 'validity', {get: () => ({valid: false, typeMismatch: true})});
    assert.equal(f.submit().prevented, true);
    assert.equal(f.nodes.find(node => node.className === 'nc-field-error').textContent, 'Email is invalid.');
    assert.equal(f.password.attrs.get('aria-invalid'), 'true');
});
