import test from 'node:test';
import assert from 'node:assert/strict';
import { actionDetails, initializeActionFeedback, shortFeedbackMessage } from '../../resources/js/action-feedback.js';

function fixture({method = 'POST', action = '/employees', dataset = {}, dialog = null, notice = null, valid = true, result = {isConfirmed: true}} = {}) {
    let handler, requests = 0, downstream = 0;
    const calls = [], body = {}, submitter = {textContent: 'Save changes', name: 'action', value: 'save'};
    const form = {
        method, action, dataset,
        matches: selector => selector === 'form',
        querySelector: () => method === 'DELETE' ? {value: 'DELETE'} : null,
        closest: () => dialog,
        requestSubmit: button => {
            requests++;
            assert.equal(button, submitter);
            if (valid) {
                const replay = event();
                handler(replay);
                if (!replay.prevented) downstream++;
            }
        },
    };
    const event = () => ({target: form, submitter, prevented: false, stopped: false,
        preventDefault() { this.prevented = true; },
        stopImmediatePropagation() { this.stopped = true; },
    });
    const document = {body, querySelector: selector => selector === 'dialog[open]' ? dialog : notice,
        addEventListener: (name, fn, capture) => { assert.equal(name, 'submit'); assert.equal(capture, true); handler = fn; }};
    const window = {};
    initializeActionFeedback(document, window, {fire: options => { calls.push(options); return typeof result === 'function' ? result() : Promise.resolve(result); }});
    return {form, event, calls, body, window, submit: async () => { const e = event(); await handler(e); return e; }, requests: () => requests, downstream: () => downstream};
}

test('confirmation replays the original submitter once and allows existing cleanup listeners', async () => {
    const f = fixture();
    assert.equal((await f.submit()).prevented, true);
    assert.equal(f.requests(), 1);
    assert.equal(f.downstream(), 1);
    await f.submit();
    assert.equal(f.calls.length, 1);
    assert.equal(f.requests(), 1);
});

test('cancel leaves the form unchanged and permits a subsequent attempt', async () => {
    const f = fixture({result: {isConfirmed: false}});
    await f.submit(); await f.submit();
    assert.equal(f.requests(), 0);
    assert.equal(f.calls.length, 2);
});

test('pending confirmation prevents duplicate submissions', async () => {
    let resolve;
    const f = fixture({result: () => new Promise(done => resolve = done)});
    const first = f.submit();
    await f.submit();
    assert.equal(f.calls.length, 1);
    resolve({isConfirmed: true}); await first;
    assert.equal(f.requests(), 1);
});

test('failed native validation does not leave a form preapproved', async () => {
    const f = fixture({valid: false});
    await f.submit(); await f.submit();
    assert.equal(f.calls.length, 2);
    assert.equal(f.downstream(), 0);
});

test('destructive confirmation is shown inside the active employee dialog', async () => {
    const dialog = {};
    const f = fixture({method: 'DELETE', dialog, dataset: {confirmLabel: 'Remove access', confirmText: 'History is retained.'}});
    await f.submit();
    assert.equal(f.calls[0].target, dialog);
    assert.equal(f.calls[0].icon, undefined);
    assert.equal(f.calls[0].customClass.confirmButton, 'nc-swal-confirm danger');
    assert.equal(f.calls[0].focusCancel, true);
    assert.equal(f.calls[0].text, 'History is retained.');
    assert.equal(f.calls[0].confirmButtonText, 'Remove access');
});

test('search, sign in and chat typing bypass confirmation', async () => {
    for (const options of [{method: 'GET'}, {action: '/login'}, {action: '/two-factor-challenge'}]) {
        const f = fixture(options);
        assert.equal((await f.submit()).prevented, false);
        assert.equal(f.calls.length, 0);
    }
    for (const selector of ['#composer', '.nc-new-chat-form', '.nc-edit-form']) {
        const form = {method: 'POST', action: '/messages', dataset: {}, querySelector: () => null, matches: value => value.includes(selector)};
        assert.equal(actionDetails(form).skip, true);
    }
});

test('workspace navigation submits directly without a confirmation or replay', async () => {
    for (const action of ['/platform/companies/7/workspace', '/platform/employees/workspace', '/company/switch']) {
        const f = fixture({action, dataset: {actionNavigation: 'true'}});
        const event = await f.submit();
        assert.equal(event.prevented, false);
        assert.equal(event.stopped, false);
        assert.equal(f.calls.length, 0);
        assert.equal(f.requests(), 0);
    }
    // A navigation marker cannot bypass a destructive method override.
    const destructive = fixture({method: 'DELETE', dataset: {actionNavigation: 'true'}});
    assert.equal((await destructive.submit()).prevented, true);
    assert.equal(destructive.calls[0].customClass.confirmButton, 'nc-swal-confirm danger');
});

test('flash messages use plain text and short success feedback', () => {
    const f = fixture({notice: {dataset: {feedbackType: 'success'}, textContent: 'Employee account created. Details follow.'}});
    assert.equal(f.calls[0].title, 'Account created.');
    assert.equal(f.calls[0].toast, true);
    const error = fixture({notice: {dataset: {feedbackType: 'error'}, textContent: '<script>unsafe</script>'}});
    assert.equal(error.calls[0].text, '<script>unsafe</script>');
    assert.equal(error.calls[0].html, undefined);
    assert.equal(shortFeedbackMessage('Message sent.'), 'Message sent.');
});
