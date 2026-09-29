# v3.6.10 — Desktop / Mobile Push Notification Hardening

This release separates the notification experience into three layers:

1. **Immediate in-app Reverb alert** — the Slack-style toast appears in the lower-right of the Pharma Strategies window without waiting for the notification centre API.
2. **OS-level Web Push** — when the Pharma Strategies page is hidden, another browser tab/app is active, or the user is working elsewhere, a subscribed desktop browser receives a native system notification through the service worker.
3. **Mobile/PWA Web Push** — the same service-worker push payload is used for supported installed PWAs/devices.

### Important browser requirement

Native desktop/mobile notifications require the user to grant notification permission. For delivery while the Pharma Strategies tab is not active, the device must also have a valid Web Push subscription and the server must have stable VAPID keys configured.

For local development, `127.0.0.1` / `localhost` is a secure context. For production, deploy over HTTPS.

### Push registration behaviour

If notification permission was already granted, the browser now silently repairs/registers the existing Push API subscription on page load and when the tab becomes visible. This prevents a stale server subscription from leaving a device apparently enabled but unable to receive push.

### Performance behaviour

The server-side Web Push network operation is dispatched after the HTTP response. It is therefore not allowed to hold up the sender's message request. The real-time in-app Reverb event remains `ShouldBroadcastNow`.

### Desktop notification payload

The OS notification includes the sender name as the title and the actual message preview as the body, plus a conversation URL and message identifier for reliable notification grouping/click-through.

### Local fallback

If notification permission is granted but no Push API subscription exists, a hidden Pharma Strategies tab can still show an OS-level notification through the service worker registration. This gives useful desktop feedback during local development even before VAPID push is configured.

### VAPID setup

If VAPID keys are not present:

```powershell
php artisan pharma:push-keys
php artisan optimize:clear
```

Do not rotate VAPID keys casually. Existing browser subscriptions are tied to the application server key.
