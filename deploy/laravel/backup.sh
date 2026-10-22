#!/usr/bin/env bash
# Dedicated Linux host only. Never source the application's .env in a shell.
set -euo pipefail
umask 077
export PATH=/usr/sbin:/usr/bin:/sbin:/bin
[[ $EUID -eq 0 ]] || { echo 'Run through the root backup service.' >&2; exit 1; }
stage=/var/lib/pharma-backup/staging
app=/srv/pharma/current
shared=/srv/pharma/shared
exec 9>/run/lock/pharma-backup.lock
flock -n 9 || { echo 'Another backup is running.' >&2; exit 1; }
required=(php8.4 mysqldump tar restic systemctl runuser)
for binary in "${required[@]}"; do command -v "$binary" >/dev/null; done
: "${RESTIC_REPOSITORY:?Configure an encrypted off-server repository}"
: "${RESTIC_PASSWORD_FILE:?Configure the repository password file}"
: "${BACKUP_DATABASE:?Configure the application database name}"
: "${MYSQL_CNF:?Configure the private MySQL client options file}"
[[ $BACKUP_DATABASE =~ ^[a-zA-Z0-9_]+$ ]] || exit 1
case "$RESTIC_REPOSITORY" in s3:*|sftp:*|rest:*|b2:*|azure:*|gs:*) ;; *) echo 'An off-server repository is required.' >&2; exit 1;; esac
case "$RESTIC_REPOSITORY" in *localhost*|*127.0.0.1*|*'[::1]'*|*YOUR-*) echo 'Configure the actual off-server repository.' >&2; exit 1;; esac
for secret in "$MYSQL_CNF" "$RESTIC_PASSWORD_FILE"; do
    [[ -f $secret && ! -L $secret && $(stat -c %u "$secret") == 0 ]] || exit 1
    mode=$(stat -c %a "$secret")
    (( (8#$mode & 077) == 0 )) || { echo 'Backup credential permissions are too broad.' >&2; exit 1; }
done
[[ -f $shared/.env && -d $shared/storage/app/private && ! -e $shared/storage/framework/down ]] || { echo 'Missing data or existing maintenance window; refusing backup.' >&2; exit 1; }
[[ ! -L /var/lib/pharma-backup && ! -L $stage ]] || exit 1
install -d -o root -g root -m 0700 /var/lib/pharma-backup "$stage"
services=(pharma-scheduler.timer pharma-queue.service pharma-push.service pharma-mail.service php8.4-fpm.service)
for service in "${services[@]}"; do systemctl is-active --quiet "$service" || { echo "Required service is inactive: $service" >&2; exit 1; }; done
stopped=()
maintenance=0
resume() {
    local result=0 service
    for service in "${stopped[@]}"; do systemctl start "$service" || result=1; done
    if (( maintenance )); then
        (cd "$app" && runuser -u www-data -- php8.4 artisan up --no-ansi) || result=1
    fi
    if (( result == 0 )); then stopped=(); maintenance=0; fi
    return "$result"
}
cleanup() {
    local result=$?
    trap - EXIT
    resume || result=1
    # Fixed, root-only staging paths; never recursively remove application data.
    rm -f -- "$stage/database.sql" "$stage/private-files.tar" "$stage/application.env"
    if (( result != 0 )); then logger -p daemon.crit -t pharma-backup 'Backup failed; inspect pharma-backup.service and application service health.'; fi
    exit "$result"
}
trap cleanup EXIT
trap 'exit 1' TERM INT
(cd "$app" && runuser -u www-data -- php8.4 artisan down --retry=60 --no-ansi)
maintenance=1
for service in "${services[@]}"; do stopped+=("$service"); systemctl stop "$service"; done
# A timer's already-running service must also finish before the snapshot.
systemctl stop pharma-scheduler.service
mysqldump --defaults-extra-file="$MYSQL_CNF" --single-transaction --quick --no-tablespaces "$BACKUP_DATABASE" > "$stage/database.sql"
test -s "$stage/database.sql"
tar -cf "$stage/private-files.tar" -C "$shared/storage/app" private
cp -- "$shared/.env" "$stage/application.env"
chmod 0600 "$stage/"*
# Resume before the potentially long off-server upload.
resume
restic backup "$stage" --host pharma --tag pharma-coordinated
restic check
# Keep pruning opt-in until retention is approved; never prune after a failed backup/check.
if [[ ${BACKUP_PRUNE:-false} == true ]]; then
    restic forget --host pharma --tag pharma-coordinated --group-by host,tags --keep-daily 7 --keep-weekly 4 --keep-monthly 12 --prune
fi
install -o root -g www-data -m 0640 /dev/null /var/lib/pharma-backup/last-success
date -u +%FT%TZ > /var/lib/pharma-backup/last-success
# Permit health checks to traverse only the status directory, not staging data.
chmod 0711 /var/lib/pharma-backup
echo 'Encrypted off-server backup and repository check completed.'
