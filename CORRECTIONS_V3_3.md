# Pharma Strategies v3.3 — Notifications, Web Push & PWA

## Added
- Database-backed in-app notification centre.
- Notification bell with unread count and mark-all-read action.
- Per-device Web Push subscription registration and removal.
- Encrypted push subscription data at rest and endpoint hashing for lookup/deduplication.
- Standards-based service worker for push delivery and notification click-through.
- Generic push content that does not expose message bodies on lock screens.
- VAPID key generation Artisan command: `php artisan pharma:push-keys`.
- Automatic VAPID key generation during the Windows installer.
- PWA manifest and install prompt where the browser exposes `beforeinstallprompt`.
- Mobile viewport and safe-area improvements for the chat interface.
- Automatic cleanup of expired push subscriptions.
- Push subscription cleanup when an account is permanently deleted.
- Guzzle PSR-18 client dependency for reliable Web Push transport.

## Operational notes
- Web Push requires explicit user permission.
- Production deployment must use HTTPS.
- Set `VAPID_SUBJECT` to the production HTTPS application URL.
- Keep VAPID private key and `.env` out of source control.
- The current local installer uses a generated VAPID key pair. Do not copy local keys into production.
