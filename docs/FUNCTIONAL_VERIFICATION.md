# Functional verification and realtime follow-up

Reviewed 15 October 2026. This follows [the performance audit](PERFORMANCE_AUDIT.md). Changes and tests were local; no Vultr deployment or physical-device push acceptance test was performed. Existing accounts, messages, uploads, keys and historical failed jobs were preserved.

## Changes

- Incoming messages in the selected conversation now fetch and render before the conversation-list refresh starts. Authorized message reads have a bounded timeout and share only in-flight requests.
- Catch-up polling can return messages and the scoped, paginated conversation list in one response. It renders messages first and rejects sidebar responses belonging to an abandoned page. Existing message-only clients remain supported.
- Push dispatch and realtime publication have independent completion markers. A broker outage no longer prevents push from being queued; a push queue failure no longer prevents realtime publication. Retries avoid repeating the already completed operation. On the same database connection, push insertion and its marker commit together; other queue connections retain at-least-once semantics.
- Migration `2026_10_15_000001_track_push_dispatch_independently.php` backfills already completed deliveries to prevent historical notification replay. The scheduled recovery command also finds deliveries awaiting only push dispatch. Health output includes that backlog separately.
- Enabling notifications explicitly renews the browser subscription. Automatic synchronization replaces expired subscriptions or subscriptions using an older VAPID public key. Routine synchronization preserves provider diagnostics; changing the owning account clears them. Unread badges and routine message notifications remain separate from important notification-centre entries.
- Composer's optimized class map is enabled. This reduces filesystem class lookup; it is not evidence that every page now meets a latency target.
- Local startup now gives PHP 128 MB upload/post limits and 256 MB memory. The previous PHP 2 MB upload ceiling conflicted with application attachment limits. Production already has a separate PHP upload configuration. Physical large-file uploads still need acceptance testing.

## Verification

| Check | Result |
| --- | --- |
| PHP regression suite | 145 tests, 1,730 assertions passed |
| Frontend regression suite | 95 tests passed |
| PHP formatting | Passed |
| Production frontend build | Passed |
| Isolated MySQL/Chrome acceptance | 48 layouts passed; no horizontal overflow or unhandled JavaScript exceptions |
| Restarted local server smoke check | Passed: actual page markup, redirects, 404s, protected source paths and compiled assets |

The final isolated browser run verified five live messages without reload/duplicates, unread updates and clearing, live edit/unsend, logout followed by Back, mobile menus and employee dialogs. The local web server and default/push/mail workers were restarted with the changes. Health reported no queued jobs, no pending realtime deliveries and no pending push dispatches; four pre-existing failed jobs were retained. No recent provider failure was recorded, but provider acceptance was still unconfirmed.

Regression coverage includes company isolation, authorized chat access, unread behavior, attachment restrictions, account recovery, session/history protection, combined catch-up pagination, broker and push-queue failure independence, migration replay protection, subscription replacement and diagnostic preservation. This does not mean every possible browser interaction or failure mode has been exercised.

The browser harness uses disposable synthetic accounts and an isolated database, real HTTP login/forms, separate Chrome contexts and local Reverb. It checks live rendering without reload/duplicates, unread badges, live edit/unsend, logout followed by Back, navigation and employee dialogs. Layout checks cover 16 page states at 375, 768 and 1366 pixels. Browser viewport emulation does not verify physical mobile keyboards, battery restrictions or OS notification delivery.

Run the isolated browser check with `node tests/smoke/browser-workspace.mjs`. On this workstation, setting `PERFORMANCE_DB=mysql` selects a newly created, strictly named disposable loopback database. Its fixture refuses the application database and drops only its own generated database. This optional local test requires database creation privileges; do not grant those privileges to the production application account. Synthetic reports/screenshots remain in ignored private storage.

## Performance limits and notification requirements

The earlier approximately 159 ms broker-event median excludes the recipient's authorized HTTP fetch and rendering. It must not be presented as full message delivery time. The final five-message MySQL/Chrome end-to-end samples were 1,284, 944, 1,916, 2,758 and 5,100 ms: median 1,916 ms, maximum 5,100 ms. These exceed a 500 ms target. Previous runs had medians around 2.2–2.7 seconds, but five variable local samples are insufficient to establish a sustained speedup. Query execution and PHP execution are substantially shorter than some client waits, indicating request queuing on this development setup. An optimized class map and fewer requests do not remove the server's concurrency limit.

After restart, local smoke checks measured a cold home response at 4,933 ms, login at 496 ms, authenticated-route guest redirects at 464–573 ms and compiled assets at 7–17 ms. These also rule out claiming that every page is now fast. First-request initialization, local host load and serialized serving need to be distinguished from production results.

PHP's built-in development server handles requests serially, and its multiple-worker mode is unavailable on Windows. See the [PHP development-server manual](https://www.php.net/manual/en/features.commandline.webserver.php). The local server is suitable for functional testing, not production capacity acceptance. Measure sender-submit to recipient-render percentiles on the actual Linux Nginx/PHP-FPM and WSS deployment under concurrent load before signing off latency.

The current LAN address and Reverb allowed origins match. Desktop Chrome permission for `http://127.0.0.1:8000` was granted when inspected. Permission alone does not prove delivery. Ordinary HTTP on a phone's LAN address cannot provide background Web Push; use HTTPS. iOS additionally needs an eligible Home Screen installation. An open active conversation deliberately suppresses a redundant device alert. Provider acceptance is not a device receipt; no physical-device delivery was demonstrated in this review. Previously empty acceptance diagnostics may also have resulted from subscription synchronization replacing records; that reset is now fixed.

## Remaining deployment acceptance

1. Provision the Canadian Linux host, domain and trusted HTTPS; configure Nginx/PHP-FPM, WSS proxy/origins and secure cookies using the [deployment runbook](VULTR_RUNBOOK.md).
2. Run coordinated migrations and restart default, push and mail workers plus the scheduler and Reverb. Verify queue recovery and health after a reboot.
3. Verify real push on desktop, Android and supported iOS in foreground, background and closed-app conditions. Confirm provider acceptance and actual device receipt separately; test revoked permission, expired subscriptions and account changes.
4. Verify real SMTP password recovery, private attachment upload/download, mobile keyboard behavior and loss/recovery of connectivity. Missed edits/deletions while offline and reconnect ordering need explicit staging acceptance; this review does not claim complete offline synchronization.
5. Measure concurrent chat/page latency and database load, and complete a backup/restore rehearsal. Production latency and physical notification acceptance remain open; do not label the application fully production-verified yet.
