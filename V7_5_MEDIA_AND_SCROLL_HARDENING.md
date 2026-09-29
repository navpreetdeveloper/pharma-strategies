# Pharma Strategies v7.5 — Media and Latest-Message Hardening

## Changes
- Conversation navigation only caches in-flight HTML requests; completed pages are never reused, preventing stale chat threads.
- Opening a conversation waits for initial media/layout settling and then positions the viewport at the newest message.
- New incoming/self messages use smooth bottom positioning only when appropriate.
- Image previews retry once with a cache-busting query before showing a clear unavailable state.
- Latest 100 messages remain rendered oldest-to-newest.
- Realtime message broadcasting is moved to Laravel's terminating phase so the send HTTP response does not wait for Reverb; failures in the realtime transport are logged without turning a successful message send into an error.
- Added regression coverage for latest-100 ordering.

## Render attachment storage
Chat attachments now use `CHAT_ATTACHMENT_DISK` (falling back to `FILESYSTEM_DISK`). The project includes a dedicated `chat_attachments` local disk whose root can be mounted on a Render persistent disk:

```env
CHAT_ATTACHMENT_DISK=chat_attachments
CHAT_ATTACHMENT_LOCAL_ROOT=/var/data/pharma-attachments
```

For an S3-compatible object store, the same attachment code can use `CHAT_ATTACHMENT_DISK=s3` after the Flysystem S3 adapter is installed in the deployment. The preview and download endpoints stream through Laravel's configured filesystem adapter, so the application code does not assume a local filesystem path. Render's default ephemeral filesystem can still lose locally stored attachments after a replacement/restart; durable storage is required for production media.
