# Pharma Strategies v3.6.8 — Slack-style real-time message notifications

## What changed

- Added an independent real-time user notification listener that is initialized separately from the notification dropdown.
- The in-app toast is rendered immediately when the private Reverb event arrives; notification-centre refresh runs in the background and can no longer delay the toast.
- Toasts are positioned at the viewport level with a very high stacking context, compact four-item stack, dismiss button, progress indicator, keyboard focus treatment, and responsive mobile layout.
- Toast content now shows the sender, sender initials, time, and an actual message preview instead of only a generic notification sentence.
- Live unread count increments immediately and is then reconciled with the server notification list.
- Browser push/local notification fallback uses the actual message preview and is only triggered when the document is not visible.
- Message notification payload now includes `message_preview` and `sender_initials`.
- Message notification dispatch uses `event(new WorkplaceNotificationSent(...))` with `ShouldBroadcastNow`, keeping the live path synchronous and independent of a queue worker.

## Validation

- PHP syntax lint completed for application/config/routes/tests.
- JavaScript syntax check completed for `resources/js/app.js` and `vite.config.js`.
- Notification regression test source verifies the recipient, conversation, sender, initials, and message preview payload.
- Project verification checks confirm the independent listener and immediate toast-before-refresh ordering.

## Runtime note

A live two-browser Reverb test still requires the project's Composer dependencies, MySQL database, Vite build/dev server, and Reverb process to be running on the user's machine. Those services are not available in the packaging environment, so no claim is made that the actual WebSocket connection was exercised here.


## v3.6.9 push-subscription hardening

- Fixed a production-local failure where `/push/subscribe` could return HTTP 500 with `The MAC is invalid` when an existing encrypted push-subscription row was created under a previous `APP_KEY`.
- Subscription registration now replaces the endpoint row using a query-builder delete before creating the new encrypted record, so stale ciphertext is never decrypted during re-registration.
- Web push delivery now isolates corrupted/stale device records, removes the invalid row, and continues delivering to other devices instead of aborting the whole send.
- Added a regression test for re-registering an endpoint whose stored encrypted values are unreadable.
