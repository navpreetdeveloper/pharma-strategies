# v7.2 Mobile, PWA and deployment hardening

This release is based on the uploaded project archive and is intended as a clean Render deployment package.

## Changes
- Removed committed runtime uploads, Laravel logs and generated bootstrap cache files.
- Added runtime `.gitignore` placeholders for private storage, logs and bootstrap cache.
- Kept `public/hot` out of the deployment package so Laravel always uses the production Vite build.
- Reworked Reverb browser configuration to use runtime server-provided metadata. HTTPS deployments use WSS on port 443 and do not depend on Vite build-time Render secrets.
- Kept Reverb server traffic local to the container (`127.0.0.1:8081`) behind Nginx.
- Changed the environment example to use file-based sessions, matching the Render testing setup.
- Changed notification language from desktop-specific wording to device-neutral **Enable notifications**.
- Added an iPhone/iPad installation path using Safari → Share → Add to Home Screen and clearer notification guidance.
- Improved service-worker activation and exact notification-click navigation.
- Added bounded initial chat history (100 messages) with **Load older messages** so large conversations do not make the first mobile render progressively heavier.
- Added lazy loading for chat image attachments.
- Added mobile shell/layout refinements, safe-area handling, touch behaviour and reduced-motion support.
- PHP syntax checked across the project and JavaScript syntax checked for `resources/js/app.js` and `public/sw.js`.

## Important
This package does not contain `.env`, passwords, APP_KEY, VAPID private keys, database credentials, or runtime chat uploads.

The VAPID keys still need to be configured in Render Environment Variables for background Web Push.
