# v7.3 Real-Time & Mobile Performance Hardening

This release addresses the mobile chat issues found during two-device testing.

## Real-time delivery
- Reverb remains the primary real-time transport.
- Realtime connection state is monitored in the chat page.
- If a mobile WebSocket is temporarily unavailable, the app uses a lightweight ID-based message sync request every 1.8 seconds instead of requiring a full page refresh.
- The fallback stops immediately when Reverb reconnects.
- Incoming message payloads are limited and ordered by message ID.
- Message broadcast happens before notification persistence and Web Push work.

## Faster sending
- The sender sees an optimistic message immediately instead of waiting for the HTTP request to finish.
- Server confirmation replaces the optimistic row.
- Large phone images are compressed in the browser before upload when they are over 1.5 MB, with a maximum side of 1800 px and JPEG quality around 82%.
- Notification database writes and push delivery are deferred until after the message HTTP response.

## Media presentation
- Image attachments use a compact rounded media card with a filename/action row.
- Mobile images use responsive sizing and lazy loading.
- Failed previews show an explicit fallback instead of a broken image area.

## Conversation list
- Exact duplicate direct-conversation records for the same employee pair are hidden from the UI, preserving the newest record.
- Conversation labels include the employee role/job title so similarly named users are easier to distinguish.

## Render/Reverb
- `REVERB_ALLOWED_ORIGINS` must include the Render hostname, e.g. `pharma-strategies.onrender.com`.
- `REVERB_HOST=127.0.0.1`, `REVERB_PORT=8081` remain the internal server-to-server values.
- Nginx proxies `/app/` and `/apps/` to the local Reverb process.

## Validation
- PHP syntax: 70 PHP files checked, 0 syntax errors.
- `node --check resources/js/app.js`: passed.
- `node --check public/sw.js`: passed.
- Blade inline chat JavaScript was extracted with Blade expressions substituted and passed `node --check`.
- No `.env`, `public/hot`, `vendor`, or `node_modules` is included in the release package.
- A full Docker build was not available in the validation environment; Render's Docker build remains the final integration build.
