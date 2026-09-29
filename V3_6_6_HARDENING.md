# Pharma Strategies v3.6.6 — Chat Interaction Hardening

## Implemented
- Replaced clipped per-message dropdowns with one viewport-safe, fixed-position message action menu.
- Message action menu is opened through event delegation, so it works for both server-rendered and real-time messages.
- Menu automatically chooses above/below placement based on available viewport space and stays inside viewport bounds.
- Escape, outside click, chat scrolling, and window resizing close the action menu cleanly.
- Reply, Forward, Edit and 5-minute Unsend remain server-authorized.
- Explicit JSON attachment payload is returned from the message-send endpoint so newly uploaded images/files render consistently without depending on Eloquent serialization details.
- Private image previews continue to use authenticated preview routes.
- Chat image loading is eager/async rather than lazy to avoid unreliable rendering inside the scrollable chat viewport.
- Added a feature test that sends a real fake image and verifies the authenticated private preview response and MIME type.
- Device notification fallback now checks for an actual PushSubscription before suppressing the local notification; merely having VAPID keys configured no longer prevents an in-browser notification when the device is not subscribed.
- No sample/demo data added.

## Verification performed in the build environment
- PHP syntax lint: all PHP files under app/routes/config/database/tests.
- JavaScript syntax check: resources/js/app.js.
- Vite config JavaScript syntax check.
- JSON parsing: composer.json, package.json, manifest.webmanifest.
- Static inspection for stale message-action handlers and the previous Notification model type mismatch.

## Environment limitation
The build environment does not contain Composer/vendor or a configured MySQL/Reverb runtime, so PHPUnit, a real browser session, MySQL migrations, and a live Reverb WebSocket connection were not executed here. Those require the user's local Windows environment.
