# Pharma Strategies v3.2 — UI, account and chat corrections

This release builds on the v3.1 package and addresses the post-login chat ordering and administration UX reported during local testing.

## Fixed

- Removed the default `latest()` ordering from the Conversation `messages()` relationship.
- Chat history is now explicitly loaded in chronological order by `created_at` and `id`, so messages do not appear reversed after a new login/session.
- Real-time message rendering now de-duplicates by message ID, preventing a sent message from appearing twice when the WebSocket event and HTTP response arrive close together.
- Chat composer now appends the confirmed server response and uses a safe text-only DOM renderer.
- Added a mobile conversation list/chat toggle and responsive layout for smaller screens.
- Added proper active conversation styling, message bubbles, status treatment and accessible navigation controls.
- Replaced text-only Admin/Sign out links with real styled buttons.
- Added a clearer admin dashboard with employee, conversation, message and audit metrics plus quick actions.
- Added a permanent **Delete account** action for Super Admins.
- Permanent deletion is protected from self-deletion and from removing the final active Company Super Admin.
- Account deletion is audited before the account is removed. If a user belongs to multiple companies, only their access to the current company is removed so another tenant is not affected.
- Added deletion cleanup for sessions and database notifications when a user is globally deleted.
- Renamed the visible product brand to **Pharma Strategies** throughout the UI and package configuration.
- Updated the installer/package identity and internal browser API namespace to match the new product name.

## Verification

- PHP syntax lint: passed for all PHP source/config/migration files.
- JavaScript syntax: passed for `resources/js/app.js` and `vite.config.js`.
- Composer/package JSON parsing: passed.
- Livewire remains on the patched 3.x constraint `^3.8.3`.
- Vite remains on `^8.0.0` with Laravel Vite plugin `^3.2.0`.

## Note

This is a source package. It does not include `vendor/` or `node_modules/`. Full runtime verification must still be performed in the user's Windows/PHP/MySQL environment after installation.
