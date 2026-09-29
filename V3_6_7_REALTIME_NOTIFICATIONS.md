# v3.6.7 — Real-time notification delivery + viewport-safe message menu

## Real-time notification architecture
- Chat message notifications are persisted as database notifications.
- A separate `WorkplaceNotificationSent` event uses `ShouldBroadcastNow` on the authenticated user's private notification channel.
- The browser listens directly for `.workplace.notification` with Laravel Echo/Reverb.
- The notification is shown immediately as an in-app toast and the notification centre is refreshed.
- If browser notification permission is granted and the device has no Web Push subscription, a local browser notification is shown as a fallback.
- Web Push remains available for devices with a registered subscription.
- The notification broadcast no longer relies on a queued broadcast notification.

## Message action menu
- The global action menu remains outside the chat scroll container.
- Its dimensions are constrained to the viewport and it uses overflow scrolling only if the viewport is genuinely too small.
