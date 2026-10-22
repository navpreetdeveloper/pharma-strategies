import test from 'node:test';
import assert from 'node:assert/strict';
import {isImportantNotification} from '../../resources/js/notification-policy.js';

test('routine and legacy message events update conversations without alerts', () => {
    assert.equal(isImportantNotification({data: {message_id: 4, important: false}}), false);
    assert.equal(isImportantNotification({data: {message_id: 4}}), false);
});
test('announcements and non-message notifications can alert', () => {
    assert.equal(isImportantNotification({data: {message_id: 4, important: true}}), true);
    assert.equal(isImportantNotification({data: {event: 'account_security'}}), true);
});
