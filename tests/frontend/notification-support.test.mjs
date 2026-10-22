import test from 'node:test';
import assert from 'node:assert/strict';
import { getNotificationSupport } from '../../resources/js/notification-support.js';

test('mobile LAN testing hides unavailable device notification actions', () => {
    const result = getNotificationSupport({isSecureContext: false, Notification: {}});
    assert.equal(result.available, false);
    assert.match(result.message, /secure website/);
});

test('secure browsers without notification support retain in-app updates', () => {
    const result = getNotificationSupport({isSecureContext: true});
    assert.equal(result.available, false);
    assert.match(result.message, /view updates here/);
});

test('secure browsers with notification support can enable device notifications', () => {
    assert.deepEqual(getNotificationSupport({isSecureContext: true, Notification: {}}), {available: true, message: ''});
});
