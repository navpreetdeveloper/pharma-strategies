# Pharma Strategies — Laravel 12

Pharma Strategies is a privacy-first, multi-tenant workplace communication application for Canadian pharmacy teams.

## What this package is

This ZIP is a **clean source package + Windows installer**. It intentionally does not include `vendor/` or `node_modules/`. The installer creates a fresh Laravel 12 application, merges the Pharma Strategies source into the correct directories, installs the PHP/frontend dependencies, creates `.env`, and verifies the Pharma Strategies route and Reverb command.

## No sample data

There are no seeded demo companies, employees, conversations, messages or notifications. The first real company workspace is created through `/register`.

## Requirements

- Windows 10/11
- PHP 8.2+
- Composer
- Node.js/npm
- MySQL 8+ or compatible MariaDB/MySQL

## Windows installation

1. Extract the ZIP.
2. Open PowerShell in the extracted `pharma-strategies` folder.
3. If PowerShell blocks scripts, use the process-only bypass:

```powershell
Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass
```

4. Run:

```powershell
.\PHARMA_STRATEGIES_INSTALL_WINDOWS.ps1
```

The installer will create `PharmaStrategies`.

## Database

Create an empty MySQL database:

```text
pharma_strategies
```

The installer creates `PharmaStrategies/.env` with MySQL defaults. Change only the username/password if your local MySQL differs.

Then:

```powershell
cd .\PharmaStrategies
php artisan migrate
```

Do **not** run `php artisan db:seed`. There is intentionally no sample data.

## Local development

Use three terminals from `PharmaStrategies`.

Terminal 1:

```powershell
php artisan serve
```

Terminal 2:

```powershell
npm run dev
```

Terminal 3:

```powershell
php artisan reverb:start
```

Open:

```text
http://127.0.0.1:8000
```

Local Reverb defaults to port `8081` in this package to avoid common Windows port conflicts. `REVERB_SERVER_PORT` is the port Reverb listens on; `REVERB_PORT` is the port Laravel/Echo uses for the Reverb application. They may be the same locally.

## First test flow

1. Register a company.
2. The first account becomes Company Super Admin.
3. Open Admin → Employees.
4. Create a Pharmacist and a Staff account using different test email addresses.
5. Open two separate browser sessions (normal + Incognito).
6. Sign in as each user.
7. Create a direct conversation.
8. Send a message and confirm it appears in the other browser without refreshing.
9. Confirm the Super Admin can access company conversations.
10. Review Admin → Audit Logs.

## Architecture

- Laravel 12
- Livewire 3.8.3+
- Laravel Reverb 1.12+
- Laravel Echo
- MySQL
- Blade + Tailwind CSS + Vite 8
- Company/tenant isolation
- Company Super Admin RBAC
- Audit logging
- Privacy/retention configuration
- Real-time private conversation channels

## Important security notes

The application enforces company scoping in the web middleware and conversation authorization. Super Admin access is company-scoped; a Company Super Admin cannot access another company's conversations. Platform-level administration, if enabled later, must remain explicitly audited.

This project does not claim automatic PIPEDA, PHIPA, provincial privacy-law or HIPAA compliance. Before production use, complete jurisdiction-specific privacy/legal review, security testing, backup/restore testing, production TLS/origin configuration, email delivery, upload malware scanning and operational monitoring.

## Production checklist

Before a real deployment:

- Use HTTPS and secure cookies.
- Put Reverb behind a TLS-capable reverse proxy.
- Use strong, unique Reverb application credentials.
- Configure allowed WebSocket origins to the actual company domain.
- Use a managed database with encrypted backups.
- Configure queue workers if asynchronous notifications/jobs are introduced.
- Configure real email delivery for invitations and password reset.
- Add malware scanning for private file uploads before enabling document uploads in production.
- Configure monitoring, alerting, log retention and restore drills.
- Review privacy/retention settings with qualified Canadian privacy counsel.


## Web Push / PWA

Pharma Strategies includes standards-based Web Push support and a PWA manifest. The installer generates VAPID keys once and stores them in `.env`. Keep `VAPID_PRIVATE_KEY` secret and keep the same VAPID key pair for the life of the production deployment. Set `VAPID_SUBJECT` to the real HTTPS application URL before production.

Users explicitly opt in from the Notifications menu on each device/browser. Push payloads intentionally contain generic workplace notification text rather than message bodies to reduce sensitive information exposure on lock screens.

For iPhone/iPad, users can add the site to the Home Screen as a web app; Apple documents Web Push for Home Screen web apps. Production deployment must use HTTPS.


### v3.6.9 notification subscription hardening

If browser push registration previously returned `The MAC is invalid`, re-registering the device now safely replaces stale encrypted push credentials. Invalid legacy device records are isolated and removed during delivery without interrupting other devices.
