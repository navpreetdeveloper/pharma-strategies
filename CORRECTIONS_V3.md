# Pharma Strategies v3 — correction notes

The v3 package was checked against the problems found during the previous local installation.

## Fixed

- `Company::$fillable` now includes `uuid`.
- `Company` also auto-generates a UUID during model creation as a defensive fallback.
- Registration uses a database transaction and validates unique work email addresses.
- Reverb configuration includes the required `scaling` configuration and modern application settings.
- Local Reverb defaults to port `8081` to avoid the Windows `8080` socket conflict encountered during testing.
- Vite is pinned to `^8.0.0` and `laravel-vite-plugin` to `^3.2.0` so npm does not resolve an incompatible Vite 7 / plugin 3.2 combination.
- The installer creates `.env` from the included MySQL/Reverb template.
- The installer verifies the Pharma Strategies route, Company UUID support, Reverb registration, and frontend dependency versions.
- Company middleware verifies that the current user actually belongs to the active company workspace.
- Conversation authorization is company-scoped for Company Super Admins to prevent cross-company conversation access.
- Employee creation validates unique work email addresses.
- Only a Super Admin can assign the Company Super Admin role.
- Employee management includes update/suspend/deactivate controls.

## Verification performed on the source package

- PHP syntax lint: passed for all PHP source files.
- JavaScript syntax checks: passed for `resources/js/app.js` and `vite.config.js`.
- JSON parsing: passed for `composer.json` and `package.json`.
- Pharma Strategies landing route check: passed.
- Company UUID fillable check: passed.
- Reverb scaling configuration check: passed.
- Vite 8 / Laravel Vite plugin 3.2 dependency checks: passed.

## Important

This source package still requires Composer/npm to install the framework dependencies on the user's machine. It contains no demo/sample data. A production deployment still requires security/privacy review, TLS, mail delivery, upload scanning, monitoring, backups and operational hardening.
