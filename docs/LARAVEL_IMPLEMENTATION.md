# Laravel implementation and Vultr Canada deployment

Updated 10 October 2026. This work applies to the Laravel application at the repository root. The separate Firebase edition is retained locally under `archive/editions/` and excluded from the active Laravel release.

## Versions verified from lockfiles

| Component | Version |
| --- | --- |
| Laravel | 12.69.2 |
| Laravel Fortify | 1.41.0 |
| Reverb | 1.12.0 |
| Laravel Vite | 8.3.1 |
| Firebase web SDK in the separate Firebase edition | 12.19.0 |
| Firebase Admin / Functions SDKs | 13.10.0 / 6.6.0 |

Use PHP **8.4.1 or later** to satisfy this Composer lockfile. Use Node.js 22.12 or later for frontend tooling. Local checks used PHP 8.5.11. Install dependencies from the existing lockfiles.

## Firebase features implemented in Laravel

- Platform-only company provisioning with an initial Company Admin; public registration is disabled. New Company Admins must replace their temporary password before workspace access.
- Immediate email-and-password login; optional email verification through REQUIRE_EMAIL_VERIFICATION=true; generic password-reset responses; password change and reset with session invalidation.
- Password-protected authenticator enrollment, TOTP confirmation, challenge-based login, and single-use recovery codes. Privileged MFA is required by default in production.
- Direct employee creation in Admin → Employees: administrators set the employee name, email, password and company role. Passwords are hashed; duplicate emails cannot overwrite an existing account. Invitation screens and public invitation endpoints have been removed. Historical invitation records remain in the database.
- Company-scoped employee details, status and roles. Managers cannot modify company administrators; administrator access and self-access are protected. Removing employment access preserves the account, other memberships and message history.
- Private attachments, replies, forwarding, timed edit and unsend, profiles, presence, and the notification inbox.
- Latest 50 messages with older cursor pages; bounded employee, conversation, company, audit and notification lists.
- Conversation participants control writing. Administrative reading respects the privacy policy and is audited.
- Realtime events carry identifiers; authenticated HTTP requests recheck permission before retrieving content. Browser checks detect workspace revocation every 15 seconds.
- Neutral push notifications, recipient eligibility checks, HTTPS push-provider allowlisting, redirect-disabled transport, device cleanup on logout, durable delivery records and retryable jobs.
- Operational retention settings and a preview-first retention command. File deletion is staged transactionally and retried after filesystem failures.

Other fixes include Linux-compatible Controller filename casing, the province column width, safe forwarded-name rendering, protection of committed attachments from delivery failures, dynamic attachment rendering, and production Reverb origins.

Feature parity does **not** import Firebase users, passwords, Firestore messages, or Storage files into MySQL. A production data import needs a separately verified migration. REQUIRE_EMAIL_VERIFICATION defaults to false for direct login. Verification timestamps are not fabricated. Operators can enable verification separately; the signed verification and password-recovery endpoints remain available. Existing MFA enrollment and production privileged-MFA enforcement remain in place.

## Platform administrator creation

Run `php artisan platform:create-admin person@example.com` from the Laravel root to create a standalone administrator. The command prints a generated password once and stores its hash. Add `--password` to enter a chosen password privately in the terminal. Existing platform accounts keep their password; protected company-owner accounts are not converted. Existing eligible accounts can still use `platform:super-admin EMAIL grant`.

## Local application

The updated Laravel server runs at http://127.0.0.1:8000 and uses the compiled frontend. Both new migrations were applied to the local MySQL database after creating a SQL backup in protected `storage/app/private/backups/`.

Optional email confirmation and password reset require a configured mailer. For development, the log mailer records links locally. For production, configure a real SMTP provider and test delivery.

For realtime local development, start Reverb with matching local host/port credentials. Production uses the worker described below; the existing local environment may continue to use the synchronous queue.

## Vultr release files

The templates in [deploy/laravel](../deploy/laravel/) provide:

- Nginx HTTPS serving only `public/`, a WSS proxy at `/app/`, and private Reverb publishing.
- systemd queue and Reverb services.
- PHP upload settings aligned with five files and a combined application limit of 120 MB.
- A production environment example and a release preparation script.

Replace every example domain and certificate path. Create unique Reverb credentials, a persistent protected APP_KEY, restricted database credentials and SMTP credentials. Keep secrets out of VITE variables; only the public Reverb key belongs there.

Choose the Vultr Canadian location for the application and verify the actual locations of database, file backups, mail and push providers. Hosting Laravel in Canada does not establish the location of every external service.

Prepare `/srv/pharma/shared/.env` and `/srv/pharma/shared/storage`. The shared storage needs `app/private`, `framework/cache/data`, `framework/sessions`, `framework/views` and `logs`. Give www-data access to shared storage and each release's bootstrap/cache; keep source and secrets protected. Keep private files outside the public root.

Unpack a clean source release under `/srv/pharma/releases/RELEASE_ID`, excluding local environment files, private uploads, backups, logs, node_modules, vendor, and public/hot. Run `bash deploy/laravel/release.sh` there. It links shared state, installs from locks, builds assets and prepares Laravel caches. Run with `--migrate` only after a database backup and staging migration check.

Point `/srv/pharma/current` at the prepared release, install the Nginx and systemd templates, validate Nginx with `nginx -t`, reload PHP-FPM/Nginx and restart the queue/Reverb services. The script deliberately leaves activation to the operator.

Add a www-data cron entry:

```cron
* * * * * cd /srv/pharma/current && /usr/bin/php8.4 artisan schedule:run >> /srv/pharma/shared/storage/logs/scheduler.log 2>&1
```

Use QUEUE_CONNECTION=database and CACHE_STORE=file for these single-server templates. Keep MySQL and Reverb listening privately; expose only required SSH/HTTP/HTTPS ports. Monitor disk space, failed jobs, pending message deliveries, pending file cleanup, PHP-FPM, mail errors and certificate renewal.

## Recovery, retention and rollback

`php artisan chat:retry-deliveries` requeues up to 100 pending deliveries; the scheduler runs it automatically. Queue middleware prevents simultaneous delivery for one message/recipient. Notification records have deterministic identifiers. Push itself cannot promise exactly-once delivery if a provider succeeds before acknowledgement is lost.

`php artisan chat:cleanup-files` retries up to 100 staged private-file removals. It validates company storage paths and retains failed tasks. The scheduler runs it every five minutes.

Company administrators configure retention days and administrative read access in Privacy. A blank retention period keeps history indefinitely. `php artisan chat:prune` previews expired messages; `php artisan chat:prune --apply` permanently removes expired messages, attachments and company-tagged notifications. Set CHAT_RETENTION_SCHEDULED=true only after adopting the policy; daily pruning then runs at 03:00 in the application timezone. Legal holds and backup retention require an operational policy outside this command.

Back up MySQL, private files and required encryption keys to protected, verified locations. Test restoration. Keep compatible prior releases. Rolling back the current symlink does not roll back database changes; restore or forward-fix the database when necessary. Never regenerate APP_KEY on an existing installation.

## Validation and remaining staging checks

- PHPUnit: **102 tests, 698 assertions passed** in isolated in-memory SQLite.
- Frontend: **41 tests passed**, including invalid-email feedback, inline password guidance, confirmation mismatch, server field errors, invalid-submit blocking, SweetAlert replay, cancellation, duplicate prevention and employee-dialog targeting.
- PHP syntax: **77 files passed**.
- Frontend tests: **28 passed**, including dynamic attachments, safe forwarded names, read-only actions, unsent files, mobile navigation dismissal/focus, measured chat header sizing, viewport shifts, attachment settling, history reading, safe chat navigation, request races and session expiry.
- Rendered chat JavaScript syntax, Blade compilation, route caching and Composer platform checks passed.
- Laravel production frontend build passed.
- Both migrations applied successfully to local MySQL.
- Local home, login, password recovery and health endpoints returned HTTP 200.

Tests cover tenant isolation, administrator protection, membership scope, history preservation, direct employee creation, duplicate-account password protection, disabled invitation links, platform account creation and sign-in, email verification, real TOTP enrollment, recovery-code reuse, password reset, throttling, private files, delivery failure/retry, removed recipients, history pagination, retention, cleanup failure/retry and new UI rendering.

No connected browser was available, so interactive/mobile/keyboard acceptance remains a staging check. Actual SMTP, browser push, WSS delivery, Linux services, TLS, production MySQL behavior, load limits and backup restoration have not been verified on your Vultr server. Test those before launch. The Windows installer uses the updated lockfiles and PHP requirement; its fresh installation path was syntax-checked rather than executed.

Official deployment references: [Laravel deployment](https://laravel.com/docs/12.x/deployment), [Reverb production](https://laravel.com/docs/12.x/reverb), [queue operations](https://laravel.com/docs/12.x/queues), [Fortify MFA](https://laravel.com/docs/12.x/fortify).

## Platform-managed company onboarding

Only Platform Super Admins can create companies through Companies > Add company. The form requires company details plus the first Company Admin name, unique email and a confirmed temporary password. Company, membership, privacy settings and audit records are saved in one transaction. Duplicate existing emails do not overwrite or reattach existing accounts.

Public GET and POST /register endpoints and registration links were removed. Newly created Company Admin accounts are flagged for a required password change, including accounts created through employee management. Sign-in opens the password screen; workspace HTTP and realtime access remain blocked until the password is replaced. Afterward the administrator reaches the dashboard, subject to existing email/MFA requirements. Production privileged MFA remains required. No existing accounts or memberships were changed. Temporary provisioning passwords are excluded from validation old input and audit metadata.

Onboarding validation: 87 Laravel tests / 520 assertions and 35 frontend tests passed. The production build and source verification passed. Coverage includes disabled public registration, platform-only provisioning, company/admin creation, duplicate-email rejection without account changes, temporary-password exclusions from validation input, required replacement before workspace access and preserved role destinations for existing accounts.

## User recovery and activity

Platform Users lists account identity, company memberships, account status and sign-in security without exposing passwords or authenticator secrets. Active non-platform accounts can receive a temporary password after the operator supplies their own current password, confirms an identity check and records a reason. The transaction changes the password hash, rotates the remember token, invalidates database sessions, removes push subscriptions and existing reset links, and records an audit event. The user must replace the temporary password before workspace access. Authenticator enrollment, recovery codes and memberships remain intact. Platform administrator and suspended-account resets are blocked in this directory.

People is the platform's single account-management section: All accounts handles sign-in recovery; Company access handles employee roles and memberships. Account cards explain missing workspace access and the reset dialog distinguishes the operator's password from the user's temporary password. Resetting a password never restores company access.

Activity is restricted to important account/access changes, password and authenticator changes, company/privacy changes, privileged conversation reviews and message removals. Category and company filters operate within this set. Routine sign-ins, workspace openings, conversation creation, message edits and forwarding remain in the database but are not displayed. Company activity remains tenant-scoped. Friendly action and target labels replace raw metadata, with responsive tables/cards and wrapped timestamps. Recovery reasons and IP addresses appear only in platform event details; message content, passwords and MFA secrets are excluded.

Recovery validation covers authorization, identity confirmation, current-password checks, session and token invalidation, preserved MFA and membership status, required password replacement and audit visibility. The full suite passed with 95 tests / 610 assertions; 35 frontend tests and the production build passed. Real-device layout and keyboard acceptance, production SMTP and Vultr services remain staging checks.

People navigation and important-activity filtering validation: 97 Laravel tests / 654 assertions passed. Coverage includes missing-workspace guidance for suspended memberships, correct treatment of platform accounts, exclusion of routine events under category filters, tenant isolation and preservation of all original audit records. The production asset build, PHP formatting and source checks passed. Interactive visual testing across devices remains required.
