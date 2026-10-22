import test from 'node:test';
import assert from 'node:assert/strict';
import {webcrypto} from 'node:crypto';
import {createMessageSubmission} from '../../resources/js/message-submission.js';

test('failed identical attempts retain their UUID; successful or changed sends get a fresh UUID', () => {
    const submission = createMessageSubmission(webcrypto);
    const file = {};
    const first = submission.key('Message', null, [file]);
    assert.match(first, /^[a-f\d]{8}-[a-f\d]{4}-4[a-f\d]{3}-[89ab][a-f\d]{3}-[a-f\d]{12}$/);
    assert.equal(submission.key('Message', null, [file]), first);
    assert.notEqual(submission.key('Message', 1, [file]), first);
    const second = submission.key('Message', 1, [file]);
    assert.notEqual(submission.key('Message', 1, [{}]), second);
    const third = submission.key('Message', null, []);
    submission.reset();
    assert.notEqual(submission.key('Message', null, []), third);
});
