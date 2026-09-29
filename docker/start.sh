#!/usr/bin/env bash
set -euo pipefail
cd /var/www/html

ATTACHMENT_ROOT="${CHAT_ATTACHMENT_LOCAL_ROOT:-storage/app/private}"

mkdir -p \
  storage/app/private \
  storage/app/public \
  "$ATTACHMENT_ROOT" \
  storage/framework/cache \
  storage/framework/sessions \
  storage/framework/views \
  storage/logs \
  bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache "$ATTACHMENT_ROOT"
chmod -R ug+rwX storage bootstrap/cache "$ATTACHMENT_ROOT"

# Fail early on missing production secrets/config.
if [[ -z "${APP_KEY:-}" ]]; then
  echo "ERROR: APP_KEY is not configured. Add it in Render Environment Variables."
  exit 1
fi

# Run database migrations BEFORE clearing Laravel's cache.
# This is important for a fresh/empty database because the
# database cache table does not exist until migrations run.
if [[ "${RUN_MIGRATIONS:-true}" == "true" ]]; then
  for attempt in {1..12}; do
    if php artisan migrate --force; then
      break
    fi

    if [[ "$attempt" == "12" ]]; then
      echo "ERROR: Database migrations did not succeed after 12 attempts."
      exit 1
    fi

    echo "Database is not ready yet; retrying migration in 5 seconds (attempt $((attempt+1))/12)..."
    sleep 5
  done
fi

# Now that the database schema exists, Laravel cache/config commands
# can safely run.
php artisan optimize:clear
php artisan config:cache
php artisan route:cache

exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf