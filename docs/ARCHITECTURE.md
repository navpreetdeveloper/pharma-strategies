# Codebase guide

Latest reviews: [company privacy and performance](COMPANY_PRIVACY_REVIEW.md) and [production acceptance](PRODUCTION_ACCEPTANCE.md). These distinguish local regression checks from production infrastructure and latency acceptance.

This is the Laravel application. The older Firebase edition is kept under `archive/` and is not part of the running application. Start with the root README for installation and deployment.

## Directory ownership

```text
app/
  Enums/                    Shared company role values
  Http/
    Controllers/            HTTP orchestration, redirects and responses
      Api/V1/               Versioned JSON endpoints
    Middleware/             Session, security, company and role checks
    Requests/               Account action validation and authorization
    Resources/              Explicit public JSON fields
  Services/
    Accounts/               Employee access, recovery, deletion and session revocation
    Chat/                   Conversation queries/creation, sending, forwarding and attachment limits
    Companies/              Company and first-administrator provisioning
  Models/                   Relationships, casts and account access policy
  Events/                   Authorized realtime event identifiers
  Jobs/                     Durable message delivery and separate push delivery
  Console/Commands/         Operations, administration and retention
config/                     Environment-backed configuration
database/migrations/        Complete schema history, including compatibility migrations
routes/
  web.php                   Public entry point and feature route loading
  web/auth.php              Sign-in, recovery, verification and authenticator routes
  web/platform.php          Platform-only administration
  web/workspace.php         Company-scoped pages and browser actions
  api.php                   Versioned same-origin JSON API
  channels.php              Private/presence channel authorization
resources/
  views/components/         Reusable Blade components, including account dialogs
  views/platform/users/     Account directory and separate recovery dialogs
  js/state/                 Feature-owned application state
  js/http.js                Shared session/CSRF request helper
  js/chat-*.js              Chat runtime, navigation, read state, recovery and viewport
  css/components/           Foundation/chat, shared workspace and administration styles
public/                     Only publicly served files; compiled assets and service worker
storage/                    Private files, logs, sessions and generated runtime data
tests/Feature/              HTTP, authorization, transaction and database regression tests
tests/frontend/             UI behavior and state regression tests
deploy/laravel/             Production environment and process/server templates
docs/                       Architecture and API contracts
```

The CSS imports keep their established cascade order. Reordering them can change existing responsive styles. Styles have been separated without changing their selectors; confirmed unused conversation-label rules were removed. Shared message serialization, attachment preview types, province values and account session cleanup now have one source each.

## Business logic and presentation

Controllers validate HTTP input, obtain the authenticated actor and current company, invoke services, and choose an HTML or JSON response. They do not pass UI markup into business services. Services accept explicit actors, companies, data and audit context; company management services recheck current permissions under the company lock. `AccountLifecycle`, `EmployeeAccess`, `CompanyProvisioning`, `CreateConversation`, `SendMessage` and `ForwardMessage` own their database transactions.

`ConversationDirectory` is shared by HTML and JSON lists, including visibility and unread rules. `MessagePayload` handles message, reply, forward and attachment serialization. Forward references omit source bodies so later edits in the original conversation are not exposed to recipients of the saved copy. Unread counts continue to include retained messages from deleted accounts. Resources whitelist API account/conversation fields. Authentication controllers still coordinate Laravel's session, MFA and password-broker flows; changing these flows should preserve their security middleware and regression tests.

Use PascalCase PHP classes, camelCase methods, snake_case database fields, kebab-case JavaScript files and `nc-` CSS component names. Use the `CompanyRole` enum for role lists instead of copying arrays. Existing persisted role values and public route names are retained.

## State ownership

The database is authoritative for identity, membership, message IDs, read cursors and important notifications. Browser state is transient and never stores passwords or authenticator secrets.

| Owner | State and lifecycle |
| --- | --- |
| `state/presence-store.js` | User presence keyed by ID; shared across chat list HTML replacements; reconnect snapshots mark departed users offline |
| `presence-state.js` | Presence transport, fallback requests and DOM rendering |
| `chat-read-state.js` | Coalesced read acknowledgments; respects background tabs and older-message reading; disposed when a chat closes |
| `chat-runtime.js` | Current composer, attachments and message actions; aborts requests and removes listeners on replacement |
| `chat-navigation.js` | Selected chat and stale navigation cancellation |
| `chat-recovery.js` | Visible-tab catch-up and reconnection reconciliation |
| `app.js` | Page-level realtime connection and notification integration |

Do not infer permission from cached browser state. Every message/history/file request is authorized again on the server. Live events carry IDs; clients fetch authorized content. Add state to its feature owner instead of adding unrelated globals. Do not persist private chat content in local storage or the service-worker cache.

## Errors and transactions

Validation errors retain Laravel's field-keyed `message` / `errors` contract and display inline. Recovery dialogs reopen for the affected account, without flashing passwords into old input. Navigation does not need confirmation; irreversible actions do. Authenticated JSON failures preserve HTTP status codes. Unexpected server errors return a generic message and `server_error`, with no SQL details or stack traces; Laravel reports the underlying exception.

Use transactions for multi-record changes. Forwarding to multiple conversations is atomic: every copy commits or none does. File changes cannot be rolled back by SQL, so failed send/forward files are staged through `PrivateFileCleanup` for retryable removal. Outbox records commit with messages; queue dispatch follows commit. Broadcast/provider outages must not undo saved messages or block the separate delivery queues. Do not catch errors and return success.

## Configuration and storage

Read environment values only through `config/*.php`; application code calls `config()`. `.env.example` is for local setup, and `deploy/laravel/production.env.example` is the production template. Keep real `.env`, application keys, database credentials, VAPID private keys, sessions and logs outside source control. Vite public configuration is compiled at build time: frontend environment variables must never contain secrets.

The database stores accounts, company memberships, removed-access snapshots, messages, read cursors, notification subscriptions and audit records. Private uploads live under `storage/app/private/companies/`. Back up both. `vendor/`, `node_modules/`, compiled assets and runtime caches are generated rather than source architecture; install dependencies from lockfiles. Historical migrations and archived source are not safe candidates for arbitrary deletion.

## Account lifecycle

Company administrators remove a company membership, not the sign-in account. A removed-access snapshot preserves the role and company profile. Platform Super Admins use **People → All accounts → Restore access** to recover that membership; password hashes and MFA remain unchanged. Older removals can be recovered from retained audit evidence, with an explicit employee role when no snapshot exists. Recovery cannot grant an unrelated membership, reactivate a suspended account or open an archived company.

**Delete account** is permanent and requires the acting administrator's password, the target email and a reason. Platform administrator accounts and the last active administrator of a company are protected. Credentials, sessions, reset tokens, push subscriptions, notifications, memberships and participation records are removed. Shared messages/files remain, with the missing author shown as **Deleted account**. Accepted invitation history can retain null user references; unaccepted invitations for the deleted email are revoked. Audit history is retained. This is an account deletion operation, not an erasure of every mention of the person inside shared message text or retained audit records.

Run migrations before using lifecycle controls. The lifecycle migration makes author references nullable. It refuses rollback if deleted-author history or invitation references exist; restore a compatible application release rather than discarding that history. An actually permanently deleted account cannot be restored through membership recovery.

## Adding a feature

1. Define its actor and company authorization requirements and add the appropriate middleware.
2. Validate input at the HTTP boundary; use a Form Request for a shared or substantial contract.
3. Put reusable rules and transactional mutations in the corresponding feature service. Keep tenant scope explicit.
4. Return a resource or shared payload for JSON; use Blade components for repeated markup.
5. Keep browser state inside its feature module, with teardown for replaceable views.
6. Test permitted and forbidden actions, cross-company access, failure rollback and relevant UI behavior.
7. Update [API.md](API.md) for public contract changes and the README for operational changes.

Run `composer test`, `npm run test:frontend`, `npm run build` and `composer format:check`. Tests use an isolated SQLite database. Browser/device appearance and external push delivery still require real-device checks; unit and HTTP tests do not replace those checks.

## Source and release boundaries

The root contains the Laravel entry point, dependency manifests/lockfiles, tool configuration and README. Feature code belongs under app/, resources/ and routes/; guides belong under docs/. Use the README installation commands instead of copying a fresh framework skeleton over this project.

Laravel discovers console commands and registers middleware/providers through bootstrap/. A file without an explicit class reference inside app/ is not necessarily unused. Preserve schema migrations and regression tests. The browser HTTP client is resources/js/http.js; no global Axios client is installed. Tailwind 4 is processed through the Vite plugin.

The archive retains previous editions and historical source, but their installed dependencies and Composer download cache have been removed. The Laravel application does not load that archive. Install archived dependencies from their own lockfiles only if using an old edition. Exclude archive/, development dependencies, local environment files, private data and generated test artifacts from production source uploads. The current vendor/, node_modules/ and public/build/ support local execution/builds and are generated from lockfiles.

Cleanup verification (13 October 2026): 128 Laravel tests / 1,592 assertions and 72 frontend tests passed; the production build and route loading passed. Removed the unused Axios bootstrap/client dependency, unused Autoprefixer dependency, obsolete Windows skeleton installer and source-pattern verification script, archived installed dependencies/download cache, and temporary browser audit output. The README now points to consolidated guides in docs/. Actual uploads, database records, environment credentials and schema history were preserved.

## Runtime performance

The current [performance audit](PERFORMANCE_AUDIT.md) records the 14 October 2026 changes and limitations; the [Vultr runbook](VULTR_RUNBOOK.md) describes release preparation and acceptance. Current validation is 139 backend tests / 1,643 assertions and 85 frontend tests. Older figures below describe their historical checkpoints.

Guest pages load public.js plus the shared page-ui module; authenticated pages additionally load app.js and realtime/session modules. Chat view fragments omit the outer layout, and fresh permission hints are calculated once by the controller. These hints do not replace authoritative mutation checks. Shared message reads last only while a request is in flight. SessionContext and session-access.js validate restored private views against the current identity, workspace role and security state. Logout clears the server session before browser push cleanup and broadcasts successful logout to other tabs.

Security reset/verification notifications use encrypted jobs on the dedicated mail queue. Local email previews are private files, never application log entries; production uses SMTP. Default delivery, provider push and security mail have independent workers. The delivered_at index supports outbox recovery; provider diagnostics distinguish acceptance from device receipt. No private chat response cache or globally cached membership authorization was added.

`scripts/serve-local.ps1` runs the Windows test server with CLI OPcache enabled, checking source timestamps on every request. The default `php artisan serve` process does not inherit command-line PHP flags from its parent. The built-in development server still serves one request at a time on Windows; production uses Nginx and PHP-FPM. Use `deploy/laravel/php-performance.ini` with the OPcache extension on PHP-FPM, alongside the upload configuration. Do not cache authenticated HTML or private chat data in browser/service-worker storage.

Conversation sidebar refreshes use one request at a time, with at most one follow-up for a burst of events. Navigation aborts stale sidebar work and responses are checked against the current view version. A ten-second deadline prevents a stalled request from blocking future refreshes. Reverb publishing uses a one-second connection deadline and two-second request deadline; durable delivery jobs still retry transport failures. Chat and device push remain on separate workers.

The local server must run from public/: Laravel's development router resolves index.php against its working directory. Setting the document root alone does not set that working directory. The startup script uses an absolute document root and changes into public/ before launching PHP.

Runtime verification uses npm run test:local against a running loopback server. It checks real home/login markup, guest redirects, custom 404 responses, protected source paths and compiled assets, and rejects PHP startup errors even when the server returns HTTP 200. Earlier status-only 6?8 ms measurements were invalid because they measured error responses. After correcting the startup directory, repeated valid login responses measured approximately 108?153 ms locally; timings vary with load. The application checks passed with 128 backend tests / 1,592 assertions, 76 frontend tests, production build and formatting checks. An isolated realtime publish probe was accepted in approximately 228 ms; this does not establish device push-provider latency.

## Reliable submissions and recovery

`Chat/MutateMessage` owns locked edits and unsends with transactional audit records. `SendMessage` supports scoped submission UUIDs and content fingerprints; the browser retains an unchanged failed attempt ID in `message-submission.js`. UUIDs remain optional for legacy clients.

Conversation views carry a database snapshot cursor. `chat-recovery.js` drains ordered database pages independently of live-rendered IDs and rotates bounded visible-row reconciliation batches. `message-reconciliation.js` preserves unchanged media and active edit forms, rejects older edit responses and irreversible-unsend reversals, and keeps pruned IDs deduplicated against late reads. `MessagePayload` supplies revision fingerprints. SQL exception reporting omits private bindings.

The coordinated encrypted backup templates and external launch gate are documented in [Production acceptance](PRODUCTION_ACCEPTANCE.md). A fresh backup status stamp does not certify a successful restoration.

Active-thread notifications request immediate combined catch-up instead of a redundant standalone sidebar fetch. Recovery coalesces bursts, advances the read cursor only through reconciled IDs, and rechecks the current view before rendering. This preserves gap recovery while avoiding the periodic-poll delay in read acknowledgements.
