# Pharma Strategies: PWA and mobile app strategy

## Web / PWA

The Laravel application is now mobile-first responsive and includes a Web App Manifest and service worker. On supported browsers, employees can install Pharma Strategies to the device Home Screen and use it in standalone app mode.

Web Push is implemented with standards-based Push API / Notifications API / Service Worker support. Users explicitly opt in per device from the Notifications menu. Push payloads intentionally avoid message contents.

Production requirements:
- HTTPS for the deployed application.
- A stable production domain.
- A stable VAPID key pair. Do not rotate the VAPID key pair casually because existing subscriptions are tied to the application server key.
- Set `VAPID_SUBJECT` to the real HTTPS application URL and keep `VAPID_PRIVATE_KEY` secret.

## Google Play

The same responsive PWA can be distributed through Google Play using a Trusted Web Activity (TWA) / Bubblewrap-style packaging, or the project can later be wrapped with Capacitor if native APIs are needed. A Play-distributed TWA still uses the production web application as its backend/frontend origin.

## Apple App Store

A PWA can be installed from Safari to the iPhone/iPad Home Screen and can receive Web Push as a Home Screen web app. For App Store distribution, however, plan a native iOS wrapper (for example, Capacitor) around the same web application rather than assuming that a PWA can simply be uploaded as an App Store binary.

## Recommended production architecture

1. Keep Laravel + Livewire + Reverb as the core application.
2. Finish responsive PWA behaviour and Web Push on the web.
3. Deploy the web application over HTTPS.
4. Validate push on managed company Android and iPhone devices.
5. Package the same web application for Google Play and App Store with a native wrapper only after the web/PWA experience is stable.
6. If native push requirements become more demanding, add FCM/APNs through the mobile wrapper while keeping the Laravel notification domain model shared.
