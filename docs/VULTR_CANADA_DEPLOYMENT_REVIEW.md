# Vultr Canada deployment status

Updated 14 October 2026. Follow the [step-by-step runbook](VULTR_RUNBOOK.md) and [performance audit](PERFORMANCE_AUDIT.md). Final local verification: 139 backend tests / 1,643 assertions, 85 frontend tests, production build and content-validating server smoke checks passed. Linux and physical-device acceptance remain outstanding.

This guide applies to the Laravel application at the project root. The separate Firebase edition and original review are retained in `archive/`; they are not part of the Laravel release.

## Completed locally

- Laravel authentication, employee email/password accounts, Platform Super Admin controls and company-scoped access.
- Public company registration is disabled. Platform Super Admins provision each company and its first administrator together; new Company Admins must replace their temporary password before workspace access.
- Password recovery, optional email verification, authenticator setup and privileged MFA enforcement in production.
- People section with All accounts for sign-in recovery and Company access for employee membership management. Audited temporary-password recovery requires identity confirmation and the administrator's own password, invalidates sessions and requires a personal-password replacement. The interface explains that a password reset does not restore workspace access. Passwords are never displayed; MFA and company access are preserved.
- Filtered platform activity and company-scoped history show important changes only. Routine events remain stored but are excluded from the interface. Responsive layouts keep sensitive recovery details restricted to the platform administrator.
- Private attachment downloads, message editing/unsend, replies, forwarding and audited administrative review.
- Retryable delivery and file cleanup, bounded history loading and retention commands.
- Responsive employee editors, company switching, mobile chat viewport handling and SweetAlert action feedback.
- Versioned Nginx, PHP upload, queue, Reverb and production environment templates in `deploy/laravel/`.
- Database migrations applied locally, including the pending-delivery index and safe provider diagnostics. Password creation/replacement requires 8 characters with uppercase, lowercase and a number; the application provider is registered explicitly. Requested sign-in messages distinguish unknown email from wrong password and therefore expose account existence; the existing sign-in rate limit remains in place.

Local checks do not establish production security, capacity or device acceptance.

## Project cleanup

The active source is Laravel only. `archive/editions/firebase/` and `archive/editions/firebase-app/` retain the previous Firebase source together so their relative tooling paths remain usable. Historical release notes are in `archive/history/`. The archive is ignored by Git and source exports; preserve it separately if the old edition is needed later.

Unused Firebase and Livewire Composer dependencies were removed with their unused transitive dependencies; the existing remaining versions were preserved. The push HTTP adapter remains because `WebPushService` uses it. The unused invitation model and Laravel Firebase configuration are retained in `archive/cleanup-backup/`. Historical database tables and migrations remain intact.

## Remaining server work

1. Provision the Canadian server, Nginx, PHP 8.4.1+ with required extensions, MySQL, Composer and Node.js 22.12+.
2. Configure the real domain, DNS and HTTPS. Serve `/srv/pharma/current/public`, not the repository root.
3. Complete `deploy/laravel/production.env.example`: database, SMTP, production domain, Reverb credentials and secure cookies. Keep `APP_DEBUG=false`; use `QUEUE_CONNECTION=database`.
4. Put the production `.env` and persistent storage under `/srv/pharma/shared/`. Preserve the existing APP_KEY when migrating encrypted records. Configure write permissions for storage and bootstrap/cache.
5. Export/import the existing database and transfer private uploads separately. Copying source does not transfer accounts or chat history.
6. Prepare a release with `release.sh`, run Composer platform checks and migrations, then switch `/srv/pharma/current` after staging checks.
7. Install/start chat, push, mail and Reverb services plus the scheduler timer. Security emails use encrypted jobs on the dedicated mail queue. Use the timer or cron, not both. Restart long-running services after releases. The release script prepares a release; it does not install services or activate it.
8. Configure Canadian backups for both the database and uploads, verify restoration, monitor disk/failed jobs/errors and document rollback.
9. Enable browser push only after configuring VAPID credentials and testing delivery. Enable scheduled history retention only when the intended policy and server scheduler are configured.
10. Test administrator MFA, password recovery, tenant isolation, access removal, WSS delivery, uploads, mobile keyboards, concurrent usage and service recovery after a reboot.

Malware scanning, backup retention and legal-hold procedures need operational decisions if required for your document-handling policies. No Vultr server deployment or live server acceptance has been performed in this workspace.

## Where data lives

The current local database is `pharma_strategies` on `127.0.0.1:3306`, managed by XAMPP under `C:\xampp\mysql\data\`. Uploads are under `storage/app/private/companies/`; sessions are database records, and logs are under `storage/logs/`.

In the supplied Linux layout, private uploads resolve to `/srv/pharma/shared/storage/app/private/companies/`. The actual production database location depends on the database service you configure. Verify the locations of the database, files and backups; an application field saying Canada does not establish residency.

Removing employee access deletes the selected company's membership and invalidates existing sessions. Accounts, messages and other company memberships remain. Employees without another active company membership cannot sign in. There is no separate Removed employees screen.

## Deployment files

| File | Purpose |
| --- | --- |
| `deploy/laravel/production.env.example` | Production configuration template |
| `deploy/laravel/nginx.conf` | HTTPS, PHP routing and WebSocket proxy |
| `deploy/laravel/php-upload.ini` | PHP upload limits |
| `deploy/laravel/php-performance.ini` | PHP-FPM opcode and path cache tuning |
| `deploy/laravel/pharma-queue.service` | Background worker |
| `deploy/laravel/pharma-reverb.service` | Realtime service |
| `deploy/laravel/release.sh` | Dependency installation, frontend build and Laravel caches |

See [LARAVEL_IMPLEMENTATION.md](LARAVEL_IMPLEMENTATION.md) for implemented behavior and the original review in [archive/history/](../archive/history/).
