# Company privacy and performance review

Reviewed 21 October 2026. This review covers application changes and local tests, not a certification of production infrastructure or legal compliance.

## Implemented

- `PrivacySetting::administratorReviewAllowed()` is the single review-permission rule. An absent policy denies nonparticipant administrator review. New Eloquent policies and the database default use participants-only access. Existing saved settings are preserved, including administrator review already enabled by a company.
- Only an active Company Super Admin in that company may change its policy. `UpdatePrivacyPolicy` rechecks the actor under a company lock and saves the policy and audit together. Platform Super Admins see a read-only summary and cannot enable their own review permission through this endpoint. Server operators remain a privileged infrastructure role, as disclosed in the notice.
- Conversation participants do not query administrator review settings on each authorization. Ordinary employee conversation lists also skip that policy query. Selected conversations are authorized once rather than twice during page assembly. Fresh server-side authorization remains mandatory; permissions are not cached across requests.
- `PrivateAttachmentPath` resolves files only inside the owning company's directory on the private local disk, including a real-path boundary check. Downloads, previews and forwarding reject foreign-company paths, traversal, missing files and paths escaping through symlinks. Downloads no longer fall through to legacy public directories. Legacy files outside private company directories require an explicit, audited migration before they can be served.
- Attachment metadata must agree with both its message and conversation company. Missing private files return 404 instead of an internal download error.
- `/privacy` explains collection, conversation review, passwords, notifications, retention, account removal, operator access and hosting limits. `/workspace/privacy` exposes only the currently authorized company's settings and contact, with no mutation controls. A footer link makes the notice discoverable. The app's shared responsive layout and metadata are used.

The notice describes the implemented service. The operator must finalize its identity/contact, actual hosting and backup regions, provider list and retention obligations before public launch. An intended region in a database field does not prove Canadian residency. The application does not implement end-to-end encryption; a server operator with database access is outside the ordinary company membership boundary.

## Security boundary

The application uses shared database tables with explicit company IDs and server authorization, not a separate database per customer. Company membership alone never grants access to another company's records. Administrators of one company cannot review another company's conversations. Platform Super Admin review is a separate privilege, read-only for chat, requiring the selected company's saved permission and an audit record. Participant access still applies when review is disabled.

Do not put private conversations into shared response caches, a CDN cache, browser local storage or service-worker caches. Do not remove authorization or revocation checks to improve speed. Adding a cache requires company, actor and permission-aware keys, plus revocation handling; a shared cache key is insufficient.

## Migration

`2026_10_21_000001_default_company_review_to_participants_only.php` changes the database default only. It does not overwrite existing policy rows or delete messages, files or accounts. Back up the database and apply the migration as part of the coordinated release procedure in [the runbook](VULTR_RUNBOOK.md). New model-created settings already explicitly use the safe default.

The migration was applied to the loopback local database after a protected SQL backup. No remote deployment was performed.

## Further performance work, in order

1. Deploy staging under Nginx/PHP-FPM with OPcache, HTTPS/WSS and supervised dedicated default, push and mail workers. The Windows development server is not a production capacity test. Keep the durable database outbox and catch-up recovery.
2. Measure page response time, database time and query count, submit-to-recipient-render latency, queue age and WebSocket reconnect rates. Use representative active-user counts and long conversations; record median, p95 and p99. Never log message bodies, credentials or subscription endpoints in these measurements.
3. Use measured slow queries and MySQL EXPLAIN before adding indexes or cached summaries. Existing timeline pagination/indexes should be retained. Precomputed unread totals need an explicit reconciliation design before replacing authoritative read cursors.
4. Consider Redis for queues/cache only when queue polling or database pressure is demonstrated. It needs supervised services, failure recovery and isolation tests; changing a driver alone does not establish a speed improvement. Keep slow mail/push providers off the live-message queue.
5. Add capacity limits and alerts for worker failures, failed jobs, oldest pending delivery, disk use, backup age and FPM/database saturation. Finish real-device push receipt, SMTP delivery and off-server backup restore tests.

Further architectural work should extend the existing controllers, feature services, explicit payloads and browser feature state rather than introducing a second framework. Add new authorization scenarios to the tenant matrix when exposing a new endpoint. Shared business rules belong in services; validation and HTTP responses belong at the controller boundary.

## Verification

- Vite production build passed and all 105 frontend regression tests passed.
- Chrome/MySQL functional checks passed 54 page/viewport combinations at 375, 768 and 1366 pixels, including the public notice and authenticated company policy. No horizontal overflow or unhandled browser exceptions were reported. Real isolated logins, five live messages, unread counts, missed edit/unsend recovery, menus, employee dialogs and logout/Back protection passed.
- Local HTTP smoke returned the actual public privacy notice with status 200 and no synthetic private company contact in the public response.
- All 158 backend tests passed with 1,951 assertions after updating the intended read-only platform privacy behavior. The suite covers missing-policy defaults, immediate review revocation, forbidden platform policy changes, foreign-company reads/mutations/presence, private file paths, and existing account/chat regressions. PHP formatting passed.
- The browser run measured delivery samples of 46,250, 2,086, 1,861, 2,160 and 3,858 ms (median 2,160 ms, maximum 46,250 ms). This does not meet the desired fast-delivery goal. Timing instrumentation also recorded slow login, chat, broadcast-authorization and heartbeat requests on the Windows development host. Some checks ran concurrently; these observations do not isolate a single cause or establish production capacity. Nginx/PHP-FPM staging measurements and further latency investigation remain required.

Existing acceptance timings in [PRODUCTION_ACCEPTANCE.md](PRODUCTION_ACCEPTANCE.md) remain historical measurements; these query reductions do not establish a new end-to-end latency guarantee. Logs for this review are `storage/logs/company-privacy-backend-final.log`, `company-privacy-frontend.log` and `company-privacy-browser.log`; they are runtime artifacts, not public website content.

## References

- [OWASP multi-tenant security guidance](https://cheatsheetseries.owasp.org/cheatsheets/Multi_Tenant_Security_Cheat_Sheet.html)
- [OWASP authorization guidance](https://cheatsheetseries.owasp.org/cheatsheets/Authorization_Cheat_Sheet.html)
- [Office of the Privacy Commissioner of Canada: meaningful consent guidance](https://www.priv.gc.ca/en/privacy-topics/privacy-for-businesses/appropriate-handling-of-personal-information/collecting-personal-information-and-consent/consent/gl_omc_201805/)
