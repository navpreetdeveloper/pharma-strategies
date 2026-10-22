#!/usr/bin/env bash
set -euo pipefail
# Run from an unpacked, trusted release. Back up before --migrate.
release_dir="$(pwd -P)"
case "$release_dir" in /srv/pharma/releases/*) ;; *) echo "Run inside /srv/pharma/releases/RELEASE_ID" >&2; exit 1;; esac
test -f artisan && test -f composer.lock && test -f package-lock.json
test -f /srv/pharma/shared/.env
test -d /srv/pharma/shared/storage
test ! -e .env
test ! -L storage
if find storage/app -type f ! -name .gitignore -print -quit | grep -q .; then
 echo "Release contains private files; move them into shared storage first." >&2; exit 1
fi
mv storage storage.release-template
ln -s /srv/pharma/shared/storage storage
ln -s /srv/pharma/shared/.env .env
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
composer check-platform-reqs --no-dev
npm ci
npm run build
rm -f public/hot
php artisan config:cache
php artisan pharma:check-production
php artisan event:cache
php artisan route:cache
php artisan view:cache
if [[ "${1:-}" == "--migrate" ]]; then php artisan migrate --force; fi
test -f public/build/manifest.json
echo "Prepared $release_dir. Switch /srv/pharma/current after staging and backup checks."
