# Production release acceptance

Updated 19 October 2026. This checklist separates implemented safeguards from infrastructure acceptance. A successful build or configuration check is not proof of notification delivery, backup recovery or production latency. No Vultr installation is performed by preparing these files.

## Local verification

- Backend regression suite: 150 tests / 1,757 assertions passed using in-memory SQLite and a separate temporary storage tree.
- Frontend regression suite: 105 tests passed, including independent catch-up cursors, paginated recovery, stale-response protection and retry UUID reuse.
- PHP formatting and the final Vite production build passed. The Linux backup script passed Bash syntax validation; it has not been installed or executed on a Linux host.
- The additive submission migration was applied to the loopback local database after a protected database/private-file backup. Existing records were preserved. Local health showed no pending jobs, realtime deliveries or push dispatches; four historical failed jobs were retained.
- Final MySQL/Chrome acceptance passed all 48 layouts at 375/768/1366 pixels, with no horizontal overflow or unhandled browser exceptions. Verified live rendering, explicit read acknowledgement, unread updates/clearing, recovery of deliberately suppressed edit/unsend events, employee dialogs, menus and logout/Back protection.
- Local server smoke passed actual home/login content, protected guest redirects, 404/source-path protection and compiled assets at http://127.0.0.1:8001. Another project owns the more specific loopback binding on port 8000; it was left untouched. Pharma's local URL and workers were updated. Windows development/test servers now use separate OPcache IDs; see [PHP's cache-ID documentation](https://www.php.net/manual/en/opcache.configuration.php#ini.opcache.cache-id). This is separate from Linux production FPM configuration.
- Final full-browser delivery samples were 7,209, 1,369, 1,822, 1,899 and 1,601 ms (median 1,822 ms, maximum 7,209 ms). An earlier partial run measured 707–802 ms, demonstrating variability rather than a sustained performance guarantee. The complete run did not meet a 500 ms target. The local cold home request measured 3,905 ms; login measured 230 ms. Production latency remains unaccepted.
- Actual device push receipt, SMTP delivery, Linux performance, off-server backup execution and restore acceptance remain pending.

## Release changes

- The initial conversation response carries a database snapshot cursor. Catch-up advances only from completed, ordered database pages, independently of later live messages.
- Active-thread notifications trigger immediate catch-up/read acknowledgement with one coalesced follow-up for bursts, rather than waiting for the periodic poll. Recovery also reconciles bounded batches of displayed message IDs, including edits, unsends and permanently pruned rows. Unchanged revisions retain their DOM/media elements; active edit text is preserved during ordinary polling, while an authoritative unsend closes it. Previously loaded history beyond 100 rows rotates through later checks.
- Current browser sends carry a random UUID retained for an unchanged failed attempt. The server serializes submission checks and saves a unique actor/conversation/UUID plus a content fingerprint. Identical retries return the saved row, including attachments; reuse with different content returns 409. Older clients may omit the UUID for compatibility and do not receive this retry guarantee. Integrations must send `client_message_id`. Reloading an unsent browser attempt does not preserve its UUID.
- Edit and unsend operations reload under company/message locks and recheck current authorization, ownership, deletion state and time limits. Audits commit in the same transaction. Repeating a completed unsend is harmless.
- SQL exception reporting records the exception type and SQLSTATE without query text or message bindings.
- The Linux backup service creates a coordinated database/private-file/environment snapshot, uploads it through restic, checks repository structure, and records a success timestamp. The application resumes before off-server transfer. Expiry is opt-in.

Migration `2026_10_17_000001_add_message_submission_identity.php` is additive. Back up first, enter maintenance, stop scheduler/workers, migrate, activate the new frontend/backend together and restart long-lived services. Never drop existing messages to install this change. See [the runbook](VULTR_RUNBOOK.md).

## Configure and verify backups on the dedicated Linux host

1. Install a supported restic package. Create a separate off-server repository in the approved Canadian location and initialize it interactively. Do not initialize over an existing repository. Preserve its strong password independently of this server and source repository.
2. Install `backup.sh` as `/usr/local/sbin/pharma-backup` (root owned, 0750), the backup service/timer/alert templates in `/etc/systemd/system/`, and a completed `backup.env.example` as `/etc/pharma/backup.env` (root:root, 0600).
3. Create `/etc/pharma/restic-password` and `/etc/pharma/mysql-backup.cnf` as root-owned 0600 files. The MySQL file contains `[client]`, host/user/password for a dedicated backup identity. Keep secrets out of command arguments. The application's production database name must match `BACKUP_DATABASE`.
4. Review the UTC timer against operating hours. This workflow assumes the dedicated host and the supplied scheduler timer, and briefly stops PHP-FPM and application workers while taking the local snapshot. It refuses an existing maintenance window or inactive required service. Do not use it unchanged on a shared PHP-FPM host or alongside another scheduler cron job.
5. Run `systemctl daemon-reload` and one manual `systemctl start pharma-backup.service`. Inspect its exit status and `journalctl -u pharma-backup.service`. Verify an actual remote snapshot with the expected database, private archive and protected environment file. Then enable `pharma-backup.timer`.
6. Connect the critical `pharma-backup` journal alert and failed unit state to the operator's monitoring service; the supplied alert unit records locally and does not deliver email/SMS by itself. Alert on the timer not firing, repository errors, insufficient staging disk space, and `/var/lib/pharma-backup/last-success` older than 26 hours. Do not expose that status path through the web server.
7. Approve expiry before setting `BACKUP_PRUNE=true`: 7 daily, 4 weekly and 12 monthly snapshots in the dedicated `pharma-coordinated` group. Backup expiry is separate from chat retention and legal hold decisions. Restic's normal repository check does not read every archived data block; schedule full or sampled data checks and restore rehearsals separately.

The design follows the official restic documentation for [backup](https://restic.readthedocs.io/en/stable/040_backup.html), [retention](https://restic.readthedocs.io/en/stable/060_forget.html) and [restore](https://restic.readthedocs.io/en/stable/050_restore.html).

## Restore rehearsal (never restore over live data)

Choose a specific snapshot ID after inspecting its metadata. Use `restic restore SNAPSHOT_ID --target /isolated/restore-directory` on an isolated machine/directory. The archive contains the original absolute staging path under that target: `var/lib/pharma-backup/staging/`. Inspect the restored SQL, private-files tar and protected environment file without printing private contents or keys. Verify files with checksums. Import SQL only into a new isolated database; extract private files only into its separate storage root. Use the saved application encryption key, disable outbound SMTP/push, clear stale sessions in the isolated copy, and verify synthetic/authorized records, account counts and attachment downloads. Record snapshot age, restore duration, missing files and the operator's result. Securely remove the isolated sensitive copy under the operational policy after approval. Keep an independent copy of the restic password; losing it makes the encrypted backups unusable.

## Go-live gate

- Run `php artisan pharma:check-production --require-push --require-backup` against the actual production configuration. A fresh backup stamp proves only that the backup script completed, not that restoration succeeded.
- Use trusted HTTPS and WSS; verify secure cookies and allowed origins. Confirm default, push, mail, scheduler and Reverb services after reboot.
- Test password-reset email through the real SMTP provider and actual device push on desktop, Android and supported iOS. Compare provider acceptance with device receipt; they are different checks.
- Test disconnect/reconnect with missed messages and mutations, a request whose response is lost after commit, and simultaneous edit/unsend requests on MySQL. SQLite regression tests cannot establish MySQL lock behavior under concurrency.
- On Nginx/PHP-FPM, measure submit-to-recipient-render median/p95/p99 with representative conversations, attachments and simultaneous active users. Separately measure page navigation and inspect FPM/database/queue saturation. The Windows serial development server cannot establish production capacity or a sub-500 ms delivery target.
- Record a successful off-server restore rehearsal and an actual backup failure alert before public access.

Infrastructure acceptance remains pending until these checks are performed with the server, domain and provider configuration. Do not substitute local test counts for this gate.
