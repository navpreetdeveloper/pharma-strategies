# Pharma Strategies v3.3.2 — Hardening pass

This release is based on v3.3.1 and hardens the registration, workspace, chat ordering, notifications, and mobile experience.

## Registration / workspace
- Company registration now redirects the newly created Company Super Admin to a dedicated `/company` workspace dashboard instead of relying on the chat page being populated.
- `/company` is company-scoped and protected by the same Super Admin / Pharmacy Manager role middleware as the administration area.
- Added a Workspace navigation action for authorised users and retained the Admin entry point.
- Registration continues to use a database transaction and creates the company, user, company membership, privacy settings, and audit event atomically.

## Message ordering
- Initial conversation rendering remains `created_at ASC, id ASC`.
- Real-time and HTTP-confirmed messages are inserted into the correct chronological position instead of blindly appended.
- Message IDs remain de-duplicated so the sender does not see the same message twice when the HTTP response and Reverb event both arrive.
- Added a feature test covering timestamp ties with message-ID ordering.

## Notifications / PWA
- Kept database + Reverb notification delivery.
- Kept opt-in Web Push subscriptions and VAPID support.
- The notification UI now hides the device-push action when push is not configured and reports errors in an accessible status area instead of using a browser alert.
- Existing service-worker behaviour avoids showing a system notification when the user is visibly viewing the same conversation.

## Validation
- PHP syntax lint passed for all project PHP files.
- JavaScript syntax checks passed.
- Composer and npm JSON manifests parsed successfully.
- Added a feature test for company registration and workspace redirect.

## Runtime note
The package is source-checked here, but a full Windows/XAMPP/MySQL/Reverb/browser test requires the target machine's installed Composer packages, database, browser permissions, and network environment. Do not treat local source checks as a substitute for end-to-end production testing.
