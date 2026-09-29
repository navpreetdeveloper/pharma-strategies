# Render deployment notes — Pharma Strategies

This bundle is based on the uploaded project contents. The archive filename is not used as the source of truth; the application files are.

## Render service
- Runtime: Docker
- Region: Oregon (US West) for Canada-first testing
- Root Directory: blank
- Docker Build Context Directory: `.`
- Dockerfile Path: `./Dockerfile`
- Plan: Free for functional testing
- Health Check Path: `/up`

## Important secrets
Do not commit `.env`, database passwords, `APP_KEY`, VAPID private key, or Reverb secret. Enter them in Render Environment Variables.

## Reverb
The container runs Nginx, PHP-FPM, and Reverb together. Nginx exposes the Laravel HTTP service on Render's `PORT` 10000 and proxies `/app/` and `/apps/` to local Reverb on port 8081.

For production HTTPS through Render:
- The browser now reads the public Reverb host/scheme/port from runtime meta tags, so Render secrets do **not** need to be baked into the Vite build.
- The browser uses `wss://<current-render-host>/app/...` on HTTPS.
- `REVERB_HOST=127.0.0.1`
- `REVERB_PORT=8081`
- `REVERB_SERVER_HOST=127.0.0.1`
- `REVERB_SERVER_PORT=8081`
- `REVERB_ALLOWED_ORIGINS` should contain the deployed hostname, for example `pharma-strategies.onrender.com`.

## Storage
Chat attachments use a private filesystem disk. Render's default filesystem is ephemeral, so it can lose locally stored attachments when the service is replaced or restarted. For durable Render-local storage, attach a persistent disk and set:

```env
CHAT_ATTACHMENT_DISK=chat_attachments
CHAT_ATTACHMENT_LOCAL_ROOT=/var/data/pharma-attachments
```

The container startup script creates and permissions that directory automatically. Alternatively, configure an S3-compatible disk after installing the corresponding Flysystem adapter.
