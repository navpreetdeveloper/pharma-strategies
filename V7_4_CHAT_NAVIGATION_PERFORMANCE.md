# Pharma Strategies v7.4 — Chat Navigation & Mobile UX Hardening

This release builds on v7.3 and focuses on the issues found during iPhone/Android testing.

## Changes

- `/chat` now opens the **Conversations inbox** by default instead of automatically opening the first thread.
- Conversation list is ordered by latest message activity.
- Removed the selected-thread chevron that appeared only on the active conversation.
- Conversation switching now uses an authenticated in-place navigation flow. The global application shell remains mounted while only the chat workspace is replaced.
- Touch/pointer prefetch starts the next conversation request early.
- A lightweight top progress indicator gives immediate feedback while a conversation is opening.
- Browser back/forward and the chat back/close controls keep the conversation URL in sync.
- Chat navigation cleans up the previous Reverb subscription, timers, event listeners, and object URLs before mounting the next thread.
- Message sending clears the composer immediately after the optimistic message is displayed, so the previous text does not remain in the typing field while the upload/request finishes.
- Failed sends restore the original text/attachments when the composer has not been reused.
- Optimistic messages show a temporary `Sending` status until the server-confirmed message replaces them.
- Image attachments preserve the complete image instead of cropping it with `object-fit: cover`.
- Attachment selection now shows visual thumbnails for images/videos and compact file-type cards for documents.
- Mobile WebSocket fallback checks run every second while Reverb is disconnected.
- A small 2.2-second safety sync runs only while the active chat is visible, even when the low-level socket reports connected. It requests only messages after the latest known message ID and prevents a manual refresh from being required if a conversation subscription silently misses an event.
- Older-message loading fetches one extra record internally so `has_more` is accurate while the UI still receives at most 100 older messages.

## Deployment

No new infrastructure is required. Continue using the existing Docker/Render deployment and environment variables from v7.3.

Do not commit `.env`, `vendor`, `node_modules`, `public/hot`, runtime logs, or private uploaded files.
