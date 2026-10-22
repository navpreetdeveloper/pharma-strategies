# Vultr Canada deployment runbook

Prepared 14 October 2026 for this Laravel project. Commands below are for a dedicated Ubuntu server, not this Windows workstation. Review paths and domain values before running them. No commands in this guide have been executed on Vultr.

## 1. Provision and establish access

Select a Canadian Vultr location and record the actual location of the database, uploads and backups. A 4 GiB instance is an initial sizing profile, not measured capacity. Use SSH keys and create a deployment operator with sudo. Confirm a second SSH connection before changing access rules.

```bash
sudo apt update
sudo apt upgrade
sudo apt install software-properties-common ca-certificates unzip nginx mysql-server ufw acl
sudo ufw allow OpenSSH
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable
```

Do not open MySQL 3306, Redis 6379 or Reverb 8081 publicly. Reverb and MySQL listen on loopback; public WebSockets pass through HTTPS Nginx. Configure only an A record unless you also configure/test IPv6 listeners and firewall rules.

## 2. Install the supported runtime

The project requires PHP 8.4.1 or newer compatible with its Composer lockfile. Ubuntu 24.04's default PHP is insufficient. If using 24.04, review and explicitly trust [Ondřej Surý's PHP repository](https://launchpad.net/~ondrej/+archive/ubuntu/php) before adding it:

```bash
sudo add-apt-repository ppa:ondrej/php
sudo apt update
sudo apt install php8.4-cli php8.4-fpm php8.4-mysql php8.4-xml php8.4-mbstring \
  php8.4-curl php8.4-zip php8.4-bcmath php8.4-intl php8.4-opcache composer
php8.4 -v
php8.4 -m
composer --version
php -v
# On this dedicated host, if the default CLI version differs:
sudo update-alternatives --set php /usr/bin/php8.4
```

Install a supported Node release satisfying Vite's requirement (Node 22.12+), using the [official Node distribution](https://nodejs.org/en/download) and verified checksums. Verify node --version and npm --version. Do not use an unreviewed curl-to-shell installer. See [Laravel deployment prerequisites](https://laravel.com/framework/docs/12.x/deployment).

## 3. Establish release and data directories

Use /srv/pharma/releases/RELEASE_ID for immutable source, /srv/pharma/shared/.env for secrets and /srv/pharma/shared/storage for persistent files. Replace YOUR_DEPLOY_USER with the operator created above.

```bash
sudo install -d -o YOUR_DEPLOY_USER -g www-data -m 2750 /srv/pharma /srv/pharma/releases /srv/pharma/shared
sudo install -d -o www-data -g www-data -m 0750 \
  /srv/pharma/shared/storage/app/private /srv/pharma/shared/storage/app/public \
  /srv/pharma/shared/storage/framework/cache/data \
  /srv/pharma/shared/storage/framework/sessions /srv/pharma/shared/storage/framework/views \
  /srv/pharma/shared/storage/logs
sudo setfacl -R -m u:YOUR_DEPLOY_USER:rwX /srv/pharma/shared/storage
sudo find /srv/pharma/shared/storage -type d -exec setfacl -m d:u:YOUR_DEPLOY_USER:rwx {} +
```

Export source with an allowlist: app/, bootstrap/ (excluding generated cache PHP), config/, database/, deploy/, resources/, routes/, scripts/, public/ (excluding hot and local generated build), docs/, artisan, composer.json/lock, package.json/lock, vite.config.js and .env.example. Exclude archive/, .git/, vendor/, node_modules/, actual storage/, .env, logs, previews and test/browser artifacts. Transfer actual data separately over an encrypted channel. Do not use a source archive that accidentally includes production credentials.

Unpack into a new RELEASE_ID directory. Create empty storage template directories expected by release.sh:

```bash
cd /srv/pharma/releases/RELEASE_ID
mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
  storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chmod -R g+rX .
sudo chgrp -R www-data .
sudo chown YOUR_DEPLOY_USER:www-data bootstrap/cache
sudo chmod 2770 bootstrap/cache
```

## 4. Database and secrets

Create the application database and a loopback-only account in an interactive sudo mysql session. Give the app account privileges only on its application database; enter a newly generated strong password privately. Do not put database passwords in command-line arguments or documentation. Require migration privileges only for the intended release procedure.

Copy deploy/laravel/production.env.example to /srv/pharma/shared/.env, set owner YOUR_DEPLOY_USER:www-data and mode 0640. Edit with a trusted local/server editor. Fill real domain, database credentials, SMTP sender/service, random Reverb credentials and allowed hostnames. Keep APP_ENV=production, APP_DEBUG=false, secure HttpOnly database sessions, database queue, Reverb broadcast and privileged MFA required.

For migrated data, preserve the existing APP_KEY and VAPID keys. Changing APP_KEY can make encrypted MFA and queued data unreadable. Import the database and copy storage/app/private/companies/ into shared storage separately; source upload does not migrate accounts or chats. Verify counts and file ownership without displaying private content. Never run migrate:fresh or db:wipe on this data.

For a genuinely new empty database, generate a random 32-byte APP_KEY directly into the protected environment before release preparation. This command refuses a nonblank key and does not print the new key:

```bash
php8.4 -r '$p="/srv/pharma/shared/.env"; $e=file_get_contents($p); if (!preg_match("/^APP_KEY=[ \\t\\r]*$/m", $e)) {exit(1);} $e=preg_replace("/^APP_KEY=[ \\t\\r]*$/m", "APP_KEY=base64:".base64_encode(random_bytes(32)), $e, 1); if (file_put_contents($p,$e)===false) {exit(1);}'
```

Generate Reverb credentials privately and keep the secret out of VITE_* variables. For a fresh push setup, first prepare a release with push disabled, then run php artisan pharma:push-keys there; it writes keys into the linked protected .env and enables push. Set a real VAPID_SUBJECT, rebuild config cache, and check production readiness again before activation. Never rotate existing keys casually. Public browser Reverb settings must use the actual domain, port 443 and https; backend Reverb settings remain 127.0.0.1:8081/http. Origins are hostnames, not URLs or a wildcard.

Local private-preview mail does not deliver email. Production needs working SMTP, sender authorization and any provider DNS records. Make the chosen email-verification policy explicit. VAPID/SMTP provider residency needs a separate decision from the Vultr location.

## 5. Prepare the release

Back up migrated production data before running migrations. For an existing installation, enter maintenance mode, stop the scheduler and all three queue workers, and allow in-flight requests/jobs to finish before the coordinated migration/cutover. Restart workers against the activated release afterward; long-running workers do not automatically reload changed job code. Migration `2026_10_15_000001` initializes independent push-dispatch state and backfills completed deliveries to avoid replaying old notifications. Do not let old workers continue publishing while that state is initialized. In the new release directory:

```bash
umask 0027
bash deploy/laravel/release.sh --migrate
php8.4 artisan pharma:check-production
# If enabling browser push:
php8.4 artisan pharma:check-production --require-push
```

The script links shared environment/storage, installs locked production PHP dependencies and locked frontend dependencies, checks PHP extensions, builds assets, caches config/events/routes/views and applies additive migrations when requested. It deliberately does not activate the release or install services. It refuses a release containing private files. A failed preparation stays inactive; correct that inactive release or prepare a new one. Do not rerun blindly after it has already created symlinks.

Ensure the deploy user's existing shared-storage access permits Artisan to write logs/cache (use a controlled ACL or run the relevant Artisan preparation commands as www-data). Do not solve permissions with chmod 777. Config cache contains secrets: restrict bootstrap/cache to the deployment operator and www-data. Remove local public/hot; this is already done by release.sh.

## 6. PHP-FPM and HTTPS Nginx

Review/copy deploy/laravel/php-upload.ini and php-performance.ini into /etc/php/8.4/fpm/conf.d/. Review php-fpm-pool.conf, install it as /etc/php/8.4/fpm/pool.d/pharma.conf and ensure its socket matches Nginx. On a dedicated host, disable the unused default pool only after confirming no other site uses it. Start with the supplied eight-child ceiling and measure worker RSS under representative load before changing it. See [PHP-FPM configuration](https://www.php.net/manual/en/install.fpm.configuration.php).

```bash
sudo php-fpm8.4 -t
sudo systemctl restart php8.4-fpm
```

Point DNS to this server. Obtain TLS before installing a configuration that references certificate files. Install certbot, create /var/www/acme, and use a temporary port-80 Nginx site with the real domain and a /.well-known/acme-challenge/ webroot at that path. Validate/reload it, then obtain the certificate:

```bash
sudo apt install certbot
sudo mkdir -p /var/www/acme
sudo nginx -t
sudo systemctl reload nginx
sudo certbot certonly --webroot -w /var/www/acme -d YOUR_REAL_DOMAIN
```

Replace every pharma.example.com occurrence in deploy/laravel/nginx.conf with the real domain, including certificate paths. Install it into sites-available and enable its symlink in sites-enabled. Disable the default site only on this dedicated host. The document root is /srv/pharma/current/public; all other source/private files stay outside it. /apps/ is blocked publicly; the application publishes to loopback. Static build assets are cached/compressed; private PHP responses are not compressed. Verify renewal with certbot renew --dry-run after the site is live.

## 7. Activate and supervise services

Use maintenance mode for a migration/data cutover where users could otherwise write to the old release. Ensure /srv/pharma/current is absent or an existing symlink, never an unrelated directory. Activate the prepared release atomically:

```bash
ln -s /srv/pharma/releases/RELEASE_ID /srv/pharma/current.next
mv -Tf /srv/pharma/current.next /srv/pharma/current
sudo nginx -t
sudo systemctl reload nginx
```

Install the reviewed units from deploy/laravel/ into /etc/systemd/system/: pharma-queue.service, pharma-push.service, pharma-mail.service, pharma-reverb.service, pharma-scheduler.service and pharma-scheduler.timer.

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now pharma-queue pharma-push pharma-mail pharma-reverb pharma-scheduler.timer
sudo systemctl restart php8.4-fpm pharma-queue pharma-push pharma-mail pharma-reverb
sudo systemctl status pharma-queue pharma-push pharma-mail pharma-reverb pharma-scheduler.timer
sudo -u www-data php8.4 /srv/pharma/current/artisan pharma:health
```

Use the supplied scheduler timer OR a cron entry, not both. Verify schedule:list and that the timer actually runs the registered commands. Enable scheduled retention only after agreeing its policy. The default and push workers poll at 0.2 seconds; mail polls at one second. Worker timeout 60 must stay below queue retry_after (database default 90). Services recover after reboot and recycle long-lived workers. Always restart FPM and long-lived workers/Reverb after activation; config changes are not picked up by existing processes. See [Laravel queues](https://laravel.com/framework/docs/12.x/queues) and [Reverb](https://laravel.com/framework/docs/12.x/reverb).

Install the reviewed logrotate.conf under /etc/logrotate.d/pharma. Laravel daily logging and logrotate retention need a consistent operational policy. Review logrotate in debug mode before enabling it.

## 8. Acceptance before opening to users

- Validate production configuration, HTTPS redirects/cert renewal, private-source 404s and absence of debug output.
- Enroll administrator MFA; test login, temporary-password replacement, SMTP recovery, verification and session invalidation after logout/access removal.
- Use two companies to verify chats, attachments, membership management and administrative review cannot cross company boundaries.
- Test new messages/unread counts without refresh, switching while events arrive, reconnect with WSS disabled, presence expiry, attachments and missed edit/delete behavior.
- On actual Android/desktop browsers grant push permission through the app; test background/new unread delivery and no duplicate/read-message notification. On eligible iOS install the Home Screen app first. Inspect safe health aggregates; provider acceptance does not prove device display.
- Test phone/tablet/desktop layouts and keyboard/scroll behavior over real HTTPS. Loopback Chrome lab checks do not replace this.
- Load-test a staging copy with synthetic data and representative concurrent users on MySQL. Measure p50/p95 request times, event-to-render delay, queue depth, PHP RSS, CPU, disk and DB connections. Avoid load testing real recipients/push endpoints.
- Reboot and check all services; complete a backup restore rehearsal. Leave maintenance mode only after acceptance.

## 9. Backups and restore rehearsal

For the coordinated encrypted backup service/timer, credential installation, opt-in expiry and isolated restore procedure, follow [Production acceptance](PRODUCTION_ACCEPTANCE.md). The supplied alert unit records a critical journal event; connect it to operator monitoring. Run `pharma:check-production --require-push --require-backup` for the final configuration/backup-freshness gate. Actual SMTP, device receipt, restoration and load tests remain separate acceptance checks.

Back up database, private uploads and the original encryption key as a coordinated set. Store encrypted backups with an explicitly chosen Canadian location, restricted access and agreed retention. Keep APP_KEY and other secrets protected separately from ordinary source archives.

For a first coordinated manual snapshot, schedule a maintenance window: artisan down, stop the scheduler timer and all three workers, allow in-flight requests/jobs to finish, then export the database and files. Use /etc/pharma/mysql-backup.cnf owned by root with mode 0600 for backup credentials; never pass a password argument. Choose a new protected backup directory per run.

```bash
# Run in an authorized root shell inside a newly created, mode-0700 backup
# directory, with application writes quiesced. Never reuse an existing snapshot.
set -euo pipefail
umask 0077
mysqldump --defaults-extra-file=/etc/pharma/mysql-backup.cnf \
  --single-transaction --quick --no-tablespaces pharma | gzip > database.sql.gz
tar -czf private-files.tar.gz -C /srv/pharma/shared/storage/app private
sha256sum database.sql.gz private-files.tar.gz > SHA256SUMS
```

Verify export exit codes (use bash set -o pipefail), checksums and permissions before resuming workers/timer and artisan up. Rehearse restoration into a separate database and separate storage path, with outbound mail/push disabled. Use the original APP_KEY. Verify account/message counts and authorized file downloads without exposing message bodies. Do not test restoration by overwriting the live database. Define off-server backup automation and alerts before launch; a local snapshot alone is insufficient.

## 10. Monitoring, subsequent releases and rollback

Monitor systemctl/journalctl, pharma:health, queue age/depth, failed jobs, pending deliveries, provider failures, PHP-FPM slow logs, database slow queries, disk and certificate expiry. Four existing local failed jobs are historical and were not deleted/replayed. Inspect individual production failures privately before queue:retry UUID; blindly replaying everything can send stale email/push. Never publish failed-job payloads or signed provider URLs.

For each release: stage/test, snapshot as needed, prepare an inactive release, review migration compatibility, switch the symlink, restart services, run smoke/health checks. For code rollback, atomically point current to the previous compatible release and restart services. Keep additive schema changes; do not automatically run migrate:rollback. Incompatible data/schema rollback requires a reviewed coordinated database/file restore under maintenance and may lose post-backup writes.

## 11. Optional Redis later

The initial database queues/file cache are supported. Adopt Redis only after measurements justify it. Install/verify the PHP Redis client for both CLI/FPM, keep Redis private with protected mode and scoped prefixes, and choose persistence for queued jobs. Drain database queues before switching QUEUE_CONNECTION. Change the queue connection in all three systemd worker commands, not just .env, then rebuild config cache/restart workers. Database sessions and authoritative membership checks can remain unchanged. Cache and queue data need separate namespaces/retention policies; do not flush queues during cache maintenance. Windows requires a compatible WSL/Docker setup and PHP client; none was configured by this work.
