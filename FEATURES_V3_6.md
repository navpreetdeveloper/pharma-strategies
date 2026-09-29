# Pharma Strategies v3.6

## Implemented in this build

- Fixed local Reverb/Echo configuration alignment and authenticated broadcast channel headers.
- Added real-time message deletion/unsend within a configurable 5-minute server-enforced window.
- Added real-time `message.deleted` broadcast event.
- Added a deleted-message state that preserves an auditable message record while removing message content and private attachments from active access.
- Prevented editing, replying to, and forwarding deleted messages.
- Added one unique direct conversation per employee pair.
- Added a database-level unique direct conversation key and migration handling for duplicate direct conversations from older builds.
- Re-opening the same employee pair now returns to the existing conversation instead of creating another thread.
- Improved real-time presence: one global company presence subscription, 30-second authenticated heartbeat, last-seen persistence, and browser lifecycle heartbeat.
- Improved in-app notification loading and explicit database notification casts.
- Added real-time notification refresh through the authenticated user broadcast channel plus fallback polling.
- Prevented authenticated users from being sent back to the public landing page at `/`; authenticated home redirects to the chat workspace.
- Reworked message actions into a professional contextual menu instead of separate arrow/ellipsis buttons.
- Reworked attachment controls with accessible SVG icons and a structured attachment sheet.
- Improved mobile chat into a true one-screen conversation experience: list view and chat view are separated on small screens, with a dedicated back-to-conversations control and simplified composer.
- Workspace and Admin navigation controls now use the same visual treatment and hover/active behaviour.
- Added feature tests for direct conversation reuse, unsend windows, and presence heartbeat.

## Testing note

Static PHP/JavaScript checks were run when preparing the package. Full PHPUnit, MySQL, Reverb, browser, camera and push-notification testing must still be performed in the user's Windows environment because the preparation environment does not contain the user's Composer vendor directory, database or running WebSocket/browser environment.

No sample/demo data is included.


### v3.6.3 reliability fixes
- Database notification model mismatch fixed.
- Existing-message three-dot action menu now opens reliably.
- Added Close chat workflow.
- Added missing-image fallback.
