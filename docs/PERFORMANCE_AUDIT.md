# Performance and production-readiness audit

See the [21 October company privacy and performance review](COMPANY_PRIVACY_REVIEW.md) for the latest access defaults, private-file hardening and targeted query reductions.

The [production acceptance guide](PRODUCTION_ACCEPTANCE.md) describes the subsequent persistence/recovery fixes and remaining infrastructure launch gate. Historical latency measurements below are not production acceptance results.

See [15 October functional verification and realtime follow-up](FUNCTIONAL_VERIFICATION.md) for the latest fixes, expanded tests and remaining deployment acceptance work.

Reviewed 14 October 2026. This is a local implementation and verification report, not a production acceptance certificate. No remote deployment was performed. Existing accounts, messages, uploads, environment keys and historical failed jobs were preserved.

## A. Confirmed stack and findings

The active application is Laravel 12.69.2 with Blade and modular JavaScript, PHP requirement ^8.4.1, Reverb 1.12.0, Fortify 1.41.0 and minishlink/web-push 11.0.0. Local PHP is 8.5.11; the database is MariaDB 10.4.32 through the MySQL driver. Vite 8.3.1 builds Tailwind 4.3.3, Echo 2.5.0, Pusher-js 8.6.0 and SweetAlert2 11.26.25. There is no active Firebase, Livewire, React or Vue runtime.

Sessions and queues use the database; cache uses files. Redis is optional, not installed or enabled by this work. Application business logic stays in feature services and actions; routes/controllers authorize requests and prepare view data; browser modules own replaceable-view state and cleanup. Source files discovered by Laravel, migrations and regression tests are required even without direct text references.

| Priority | Finding | Resolution / remaining work |
| --- | --- | --- |
| Critical local defect | The development router resolved index.php from the repository root. Error bodies could return HTTP 200. | Serve from public/ and validate actual page content. Earlier 6–8 ms status-only measurements are withdrawn. |
| High | Windows development PHP serves requests serially; normal artisan serve did not inherit CLI OPcache settings. | Explicit OPcache startup script; production uses Nginx/PHP-FPM. Concurrent production capacity remains unmeasured. |
| High | Worker sleep introduced avoidable live-event delay; provider delivery could interfere with chat if queues were combined. | Default and push workers use separate queues and 0.2-second idle polling. Mail has its own worker. |
| High | Forwarding controls repeated authorization queries during view rendering. | Compute fresh scoped UI permissions once in the controller. Mutation services still reauthorize under locks. |
| High | Restored browser history could expose stale private content after session changes. | Mask snapshots, verify current identity/scope/security context, clear private views on revocation, and propagate successful logout across tabs. |
| Medium | Guest pages loaded authenticated realtime code. | Separate guest entry; shared feedback remains available. |
| Medium | Pending-delivery recovery scanned completed deliveries. | Add delivered_at index; retain the durable outbox and retries. |
| Medium | Push failures lacked safe diagnostics; security emails could run synchronously or put reset links in application logs. | Safe aggregate diagnostics, encrypted queued mail, private local email previews. Real SMTP still needs acceptance testing. |

## B. Changes and source map

| Files / modules | Purpose |
| --- | --- |
| scripts/serve-local.ps1; tests/smoke/local-server.mjs | Correct router directory, development cache guard, real markup/redirect/asset/source-protection checks |
| ChatController; ConversationDirectory; chat/index.blade.php; chat/view.blade.php; chat-navigation.js | Fresh permission hints, indexed latest timestamp lookup, smaller chat fragments and stale-navigation cancellation |
| page-ui.js; public.js; app.js; vite.config.js; layouts/app.blade.php | Shared feedback and guest/authenticated entry separation |
| message-receiver.js; chat-runtime.js | Share in-flight message reads only; stop discarded views from rendering late events |
| session-access.js; logout.js; SessionContext; auth/workspace routes; AuthController; app.css | Scoped session checks, history masking and logout independent of slow browser push unsubscription |
| migration 2026_10_13_000001; PushSubscription; PushDeliveryDiagnostics; WebPushService; DeliverWorkplaceMessage; SendWorkplacePushNotification | Pending index, provider diagnostics, safe exceptions and consistent unread handling |
| QueuedSecurityMail; QueuedPasswordReset; QueuedVerification; User; PrivatePreviewTransport; AppServiceProvider; mail/filesystems config; bootstrap/app.php | Encrypted asynchronous recovery/verification, skip stale mail, private local previews, sensitive-input protection |
| CheckProduction; ReportHealth; deploy/laravel/* | Secret-free readiness/health commands and Linux runtime templates |
| tests/Feature; tests/frontend; tests/Performance; scripts/audit-database.php | Regression coverage, isolated benchmarks and read-only database plan inspection |

No new runtime package was added. Generated vendor/, node_modules/ and public/build/ remain local build artifacts. Historical editions remain outside the active release in archive/. Do not remove schema history or actual storage to make the tree appear smaller.

## C. Measurements

Request benchmark: isolated in-memory SQLite, 10 conversations, a primary thread with 10,000 messages, 10,900 messages total, five sequential samples, bcrypt cost 12, fake queues, PHP HTTP kernel. These measurements exclude network/server startup and are not MySQL production load tests. Reports are private under storage/app/private/performance/; they contain synthetic data only.

| Request | Baseline queries | Optimized queries | Baseline median | After query optimization median |
| --- | ---: | ---: | ---: | ---: |
| Dashboard | 12 | 11 | 24.50 ms | 25.85 ms |
| Conversation list | 18 | 16 | 52.25 ms | 53.17 ms |
| Open conversation | 56 | 34 | 85.84 ms | 79.54 ms |
| Conversation fragment | 56 | 31 | 86.67 ms | 72.62 ms |
| Sidebar | 9 | 9 | 39.51 ms | 40.06 ms |
| Older history | 12 | 12 | 23.31 ms | 24.17 ms |
| Empty updates | 8 | 8 | 9.30 ms | 9.12 ms |
| Send message | 22 | 22 | 13.07 ms | 13.63 ms |
| Password sign-in | 4 | 4 | 320.93 ms | 325.15 ms |

The fragment improvement at the query-optimization checkpoint was approximately 16%; other small timing changes are noise, not demonstrated speedups. Password hashing was not weakened to meet a timing target. after.json is the paired checkpoint above. The final run, during other local verification, measured open/fragment/sidebar/send/sign-in at 115.30/105.74/53.09/24.54/368.19 ms. Host load and later session hardening differ, so a sustained final wall-time improvement is not established by these samples. The reduction in queries and guest payload size is directly verified. final.json retains those final observations rather than replacing them with faster checkpoint numbers.

An isolated 10,000-row outbox recovery benchmark improved median lookup from 0.789 to 0.122 ms and changed SQLite's plan from SCAN to indexed SEARCH. This is an index lookup measurement, not overall application latency.

Five synthetic deliveries used a real local Reverb connection and disposable SQLite queue database. Persistence-to-browser-event median changed from 410.78 ms at sleep=1 to 159.48 ms at sleep=0.2; the latter maximum was 480.52 ms. Both drained their queues. This excludes the authorized message-body fetch, DOM paint, provider push and physical-device receipt. Fractional sleep is supported by the installed Laravel worker. Each idle database worker now polls about five times per second; monitor this cost before increasing worker count.

Guest JavaScript changed from a 208.72 KB main entry to about 85.42 KB including its shared chunk (approximately 59% less uncompressed JavaScript). Final authenticated JavaScript is about 211.22 KB including the shared chunk; the improvement concerns guest loading, not every page. Browser lab checks at widths 375, 768 and 1366 recorded LCP of 304–624 ms, no horizontal overflow and no runtime exceptions on home/login. These are six local lab samples, not field p75 Core Web Vitals or real mobile acceptance. INP and multi-user production capacity remain unmeasured.

Valid warm login responses previously measured around 108–153 ms on the corrected local server. The final smoke run under concurrent verification measured login at 168 ms and redirects including two requests at 315–355 ms. Windows serial serving and test load affect these figures. No claim that every request is under 200 ms is justified.

## D. Database, pagination and state

The read-only MariaDB audit confirmed existing message indexes on (conversation_id,id) and (conversation_id,created_at,id), participant/user-conversation keys and company membership keys. Duplicate indexes were not added. The pending-delivery audit previously selected PRIMARY with an index scan; after migration it uses message_deliveries_pending_index with ref access and an estimated one row. History uses messages_conversation_timeline_index. The tiny local updates table selected an alternate conversation index with a filesort; directory/activity ordering also uses filesorts. Re-evaluate those plans on representative production MySQL data rather than forcing indexes from a 26-message local database. scripts/audit-database.php prints counts, indexes and plans without message bodies or query bindings.

Initial chat rendering remains bounded to 50 messages, older history to cursor batches of 51 and updates to 100. Message IDs prevent duplicate rendering; scrolling/read acknowledgements are coalesced. The sidebar timestamp uses an ordered indexed latest-row lookup rather than repeatedly aggregating full histories. Browser state lives in feature modules; no private fulfilled-response cache or service-worker chat cache was introduced.

Retain database authority for memberships, account status and read cursors. No permission or conversation data is globally cached. Initial conversation GET marks its loaded messages read; live acknowledgements require the visible conversation and bottom position. Recovery primarily reconciles new message IDs: edits/deletions missed during disconnection may require reopening the conversation. This remains an acceptance item.

## E. Realtime and notifications

The sender commits the message and durable delivery row, then queues delivery. Private events carry IDs; recipients obtain bodies through authorized routes. This extra request intentionally preserves immediate access-revocation checks. Delivery completion means the broker accepted publishing, not that the recipient device displayed the message.

Visible pages reconcile messages every five seconds, presence every fifteen seconds and heartbeat every twenty seconds. Without a departure event, offline status can take sixty seconds plus reconciliation. Closed/background mobile browsers cannot rely on these timers; browser push handles that case. Typing indicators and recipient-facing delivered/read ticks were not found and were not introduced silently.

Ordinary sends do not create success popups or notification-centre entries. Incoming push is generic, grouped by conversation, suppressed when superseded/read and separated from live delivery. Diagnostics record provider acceptance/failure timestamps and numeric status without logging endpoint URLs or keys. Provider acceptance is not a device receipt. Last local health check: no pending jobs or message deliveries, four historical failed jobs left intact, one subscribed device, no recorded provider acceptance. No actual provider/device acceptance was demonstrated in this audit.

Browser push requires permission and a secure context. Plain HTTP LAN phone testing cannot prove push delivery. iOS/iPadOS requires an eligible Home Screen web app and a permission request initiated by the user. See [MDN secure contexts](https://developer.mozilla.org/en-US/docs/Web/Security/Defenses/Secure_Contexts) and [WebKit Web Push requirements](https://webkit.org/blog/13878/web-push-for-web-apps-on-ios-and-ipados/).

## F. Security boundaries

Tenant authorization, CSRF, MFA, account suspension, private attachments and transactional mutation checks remain enforced. Session checks carry only the signed-in user's identity/scope metadata; their fingerprint is not an authentication credential. Logout invalidates the session and its owned push subscription before navigation; blocked browser unsubscription no longer delays it.

Passwords remain hashes. Reset/verification jobs are encrypted, on the mail queue and skipped if stale. Local previews are .eml files outside public/; they contain sensitive links and must not be shared or shipped. Generic local-disk temporary serving is disabled; attachments use the existing authorized routes. Windows ACLs govern local file permissions; POSIX mode assumptions do not establish Windows isolation.

Requested distinct unknown-email/wrong-password responses still reveal account existence; rate limiting remains in place. Chat is not end-to-end encrypted, and authorized administrator review is an intentional policy feature. Canadian hosting alone does not establish regulatory compliance or residency for SMTP, push providers and backups.

## G. Verification

- Full backend suite: 139 tests, 1,643 assertions passed.
- Full frontend suite: 85 tests passed.
- Vite production build passed; formatter applied to changed PHP files and checked again before handoff.
- Isolated HTTP benchmarks at 1,000 and 10,000 primary-thread messages: each 1 test, 70 assertions; outbox benchmark: 1 test, 22 assertions.
- Local smoke: home/login content, guest redirects including session/status, custom 404, protected source files and all guest/auth/shared build assets passed.
- Browser lab: home/login layouts and synthetic Reverb delivery passed.

Run normal checks with composer test, npm run test:frontend, npm run build and composer format:check. Run benchmarks explicitly with php vendor/phpunit/phpunit/phpunit tests/Performance/BenchmarkTest.php and RecoveryBenchmarkTest.php. They assert isolated SQLite and never replace the real database. The queue fixture additionally refuses any database outside its disposable performance path.

## H. Deployment decision

The source is a deployment candidate. Before public release, complete real domain/TLS, SMTP acceptance, MFA enrollment, browser WSS/push tests, physical-device keyboard/background tests, two-company authorization tests, representative concurrent MySQL load, reboot recovery and a backup restore rehearsal. Linux Nginx/PHP-FPM/systemd syntax and limits must be validated on the actual server. Redis is not required for the initial database-queue deployment.

Follow [Vultr runbook](VULTR_RUNBOOK.md). Do not ship local .env, data, previews, logs or benchmark/browser profiles. No remote server or domain was configured by this audit.
