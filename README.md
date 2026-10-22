# Pharma Strategies

Laravel workplace messaging with company administration, private attachments and realtime chat. The application at this project root uses MySQL, Blade, Vite and Laravel Reverb.

## Project layout

| Path | Purpose |
| --- | --- |
| `app/` | Controllers, models, access middleware, services, jobs and commands |
| `bootstrap/`, `config/` | Application startup and configuration |
| `database/` | Database migrations and test factories |
| `resources/` | Blade templates, JavaScript and CSS |
| `routes/` | HTTP routes, realtime channel authorization and scheduled tasks |
| `public/` | Web server document root, PWA files and compiled assets |
| `storage/` | Private uploads, logs and generated runtime files |
| `tests/` | Laravel and frontend regression tests |
| `deploy/laravel/` | Vultr production environment, Nginx and service templates |
| `docs/` | Architecture, API, implementation, mobile and deployment guides |
| `archive/` | Local copies of the separate Firebase edition and historical notes; excluded from source exports |

`vendor/` and `node_modules/` are installed dependencies. Keep them for local development; install from the lockfiles when preparing a release. Keep all database migrations, even those creating historical tables.

## Codebase guide

The [production acceptance guide](docs/PRODUCTION_ACCEPTANCE.md) covers reliable chat retries/recovery, coordinated encrypted backups and the server checks required before public launch.

See [functional verification](docs/FUNCTIONAL_VERIFICATION.md) for the latest regression results, realtime changes and deployment limitations, and the [Vultr runbook](docs/VULTR_RUNBOOK.md) for production setup.

Read [Architecture](docs/ARCHITECTURE.md) for folder ownership, business services, reusable UI, state management, error handling and extension conventions. [API v1](docs/API.md) documents the session-authenticated JSON endpoints, CSRF, pagination and access rules. Web routes are split by feature; account management, company provisioning and chat creation/sending/forwarding use dedicated services.

## Requirements

PHP 8.4.1+, Composer, Node.js 22.12+ and MySQL or a compatible MariaDB installation. Run commands from this directory.

## Fresh local installation

Install dependencies with `composer install` and `npm ci`. Copy `.env.example` to `.env` only for a new installation, configure the database, and run:

```text
php artisan key:generate
php artisan migrate
npm run build
```

Start the application, Reverb, chat delivery worker and browser push worker in separate terminals: On Windows, use `.\scripts\serve-local.ps1 -PhpBinary C:\php\php.exe` for the web server in place of `php artisan serve`; it enables opcode caching and keeps source changes visible.

```text
php artisan serve
php artisan reverb:start --host=0.0.0.0 --port=8081
php artisan queue:work database --queue=default --sleep=0.2 --tries=5 --timeout=60
php artisan queue:work database --queue=push --sleep=0.2 --tries=5 --timeout=60
```

Open http://127.0.0.1:8000. To develop frontend assets, use `npm run dev` instead of the compiled build. After starting the server, run `npm run test:local` to verify page content, guest redirects, private source protection and built assets. These smoke checks detect PHP startup errors even if they return HTTP 200.

Keep the queue worker running with `QUEUE_CONNECTION=database` so push delivery does not block message requests. For browser push, run `php artisan pharma:push-keys` once and keep the generated private key secret. Set `VAPID_SUBJECT` to the real HTTPS application URL in production. Device notifications need browser permission and a secure connection; a phone visiting an ordinary HTTP LAN address can receive in-app updates but cannot enable browser push.

On this Windows installation, PHP needs `$env:OPENSSL_CONF='C:\xampp\php\extras\ssl\openssl.cnf'` in the worker terminal before starting it or generating push keys. This is a local Windows path; use the server's OpenSSL configuration on Linux.

The local PHP installation also needs its CA certificate bundle configured for push delivery. A missing bundle produces cURL error 60 even when the browser can open HTTPS sites. For this workstation, start each worker with the existing XAMPP trust bundle:

```powershell
$env:OPENSSL_CONF='C:\xampp\php\extras\ssl\openssl.cnf'
C:\php\php.exe -d curl.cainfo=C:\xampp\apache\bin\curl-ca-bundle.crt -d openssl.cafile=C:\xampp\apache\bin\curl-ca-bundle.crt artisan queue:work database --queue=default --sleep=0.2 --tries=5 --timeout=60
# Run in a separate terminal:
C:\php\php.exe -d curl.cainfo=C:\xampp\apache\bin\curl-ca-bundle.crt -d openssl.cafile=C:\xampp\apache\bin\curl-ca-bundle.crt artisan queue:work database --queue=push --sleep=0.2 --tries=5 --timeout=60
```

Both workers must stay running and need to be restarted after a reboot. Start a third worker with the same PHP certificate settings and `--queue=mail --sleep=1 --tries=5 --timeout=60` for password recovery and verification. Local mail uses private previews under `storage/app/private/mail-preview/`; it does not send email. Production uses real SMTP, the supplied systemd services and the Linux system CA store; do not copy these Windows paths to Vultr. Never disable certificate verification to work around delivery errors.


Conversation switching updates the chat view without reloading the whole page. Notification history loads when its menu opens rather than issuing another history request on each page load.

Routine chat messages update conversations and unread badges without success popups or notification-centre entries. Incoming-message device notifications group by conversation, use generic text and skip superseded or already-read messages. The open, visible conversation remains quiet; announcements still appear in the notification centre. Read state is stored per participant and advances only through messages opened or visible at the bottom of the chat. Live user events update an open conversation; visible pages use a five-second catch-up check even when the socket reports connected while the page is visible. Browser push uses a separate worker so provider outages cannot hold up live chat delivery. Install both `pharma-queue.service` and `pharma-push.service` in production. Employee login, including MFA, opens the chat list without restoring an intended conversation; administrator dashboards and mandatory password-change screens keep their role-based destinations.


## Accounts

Public company registration is disabled. A Platform Super Admin creates each company and its first Company Admin from Companies > Add company. New Company Admins must change their temporary password before workspace access. Company Admins create employee email/password accounts from Employees. Create a standalone Platform Super Admin with:

```text
php artisan platform:create-admin person@example.com --password
```

Removing employee access removes the company membership; the account and chat history remain. Platform Super Admins can restore removed memberships from People > All accounts, including the previous role and company profile when a snapshot exists. Delete account permanently removes credentials and access after administrator verification; shared chat history remains labelled Deleted account. Permanent deletion cannot be reversed, and platform administrators and the last company administrator are protected. Existing accounts and Firebase identities are not automatically imported or reattached by account creation.

New and replacement passwords require at least 8 characters, including uppercase, lowercase and a number. The registered application provider applies the same rule to employee creation, company provisioning, administrator creation and password recovery.

Password requirements update beneath the input as the user types. Form validation errors are displayed beside their fields; invalid submissions do not open a confirmation dialog. SweetAlert confirmations use compact layouts for account changes, while navigation proceeds directly. The homepage retains its existing copy with a clearly labelled illustrative team-workspace preview.

Sign-in feedback distinguishes an invalid/unrecognised email (Email is invalid.) from an existing account's incorrect password (Wrong password.). These requested messages reveal whether an email is registered; generic credentials feedback provides stronger protection against account enumeration. Sign-in remains rate limited, suspended accounts are blocked, MFA remains required for enrolled users and failed attempts clear previous pending MFA state. Passwords are excluded from old input.

Platform Super Admins use People > All accounts to search accounts and filter by company or status. People > Company access manages employee roles in a selected workspace. Passwords are stored as hashes and are never displayed. For an active non-platform account, Reset password requires the administrator's own current password, an identity check and a recovery reason. Share the temporary password securely with the verified person. The reset ends their sessions, disconnects device notifications and requires a personal password at sign-in. It preserves authenticator enrollment and company access. Accounts without an active company membership cannot enter a workspace; resetting a password does not restore that membership. Platform administrators use their own account recovery; suspended accounts are not reactivated by a reset.

Activity shows important access, security and workspace changes, administrator conversation reviews and message removals. Routine sign-ins, workspace openings, conversation creation, message edits and forwarding remain stored but are excluded from this screen, including filtered results. Platform administrators see platform and company events; company administrators and managers see only their current company's events. Recovery reasons and IP addresses are available only in the platform history. Ordinary staff cannot access administrative activity.

## Validation

```text
composer test
npm run test:frontend
npm run build
composer format:check
```

Tests use an isolated database. Use the commands above rather than source-pattern verification scripts.

## Deployment and storage

Read the [performance audit](docs/PERFORMANCE_AUDIT.md), [Vultr runbook](docs/VULTR_RUNBOOK.md), [deployment status](docs/VULTR_CANADA_DEPLOYMENT_REVIEW.md) and [Laravel implementation](docs/LARAVEL_IMPLEMENTATION.md). Mobile/PWA notes are in [MOBILE_APP_AND_PUSH.md](docs/MOBILE_APP_AND_PUSH.md).

Accounts, memberships, chat messages and sessions are database records. Uploads are stored under `storage/app/private/companies/` and served through authorized Laravel routes. Back up both the database and private uploads.

Deploy the Laravel source and `deploy/laravel/` templates. Exclude `archive/`, local `.env` files, local logs/caches, test caches and `public/hot`. Transfer production data separately. Serve only `public/`.

### Mobile realtime testing

Keep the web server, Reverb, default queue worker and push worker running in separate terminals. For LAN testing, serve with `php artisan serve --host=0.0.0.0 --port=8000` and include your computer's current LAN IP in `REVERB_ALLOWED_ORIGINS` alongside `127.0.0.1,localhost`. Origins are hostnames/IP addresses, without a scheme or port. Restart Reverb after changing them; do not use a wildcard in production.

Live messages arrive through authenticated user channels. Visible chat pages also reconcile new messages and unread counts every five seconds, including when a socket is connected but misses an event. Presence updates live and reconciles against server heartbeats every fifteen seconds. Heartbeats run independently of WebSockets every twenty seconds while the application is visible. Without a live departure event, a user becomes offline after sixty seconds without a heartbeat, plus the next reconciliation interval. Only Online/Offline is displayed.

## Local port conflicts

On this workstation Pharma Strategies runs at `http://127.0.0.1:8001` because another project uses loopback port 8000. Start it with `powershell -ExecutionPolicy Bypass -File scripts/serve-local.ps1 -PhpBinary C:\php\php.exe -Port 8001` and keep the local `APP_URL` consistent. Run `npm run test:local -- http://127.0.0.1:8001` to test that instance. Development servers use an isolated Windows OPcache ID. Existing browser tabs need a refresh for rebuilt assets; notification permission is specific to the new origin.
