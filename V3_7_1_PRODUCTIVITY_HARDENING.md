# Pharma Strategies v3.7.1 — Productivity & Reliability Hardening

- Removed the 15-second notification polling loop from the normal real-time path. New messages use the recipient private Reverb channel; durable history refreshes on demand and on visibility return.
- Added a confirmed Clear action to the notification centre. It deletes only the signed-in user's notification records and never chat messages.
- Added self-service password change for every authenticated employee with current-password verification, strong validation, other-session invalidation, audit logging and temporary-password clearing. Administrator-created employee accounts are marked as temporary-password accounts.
- Added explicit download controls to image, video and document attachments. Preview/open and download are separate actions.
- Added regression coverage for password change, notification clearing and attachment download.

The in-app notification path remains `ShouldBroadcastNow` over a private Reverb channel. OS-level Web Push is a separate browser/provider delivery path and can have network/provider latency.
