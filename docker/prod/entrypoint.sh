#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

# Ensure SQLite file exists. The host bind-mount may be empty on first boot.
if [ ! -f database/database.sqlite ]; then
  echo "[entrypoint] creating empty SQLite database file"
  install -m 0664 /dev/null database/database.sqlite
fi

# Run migrations only when explicitly requested (the deploy workflow runs
# migrations in a one-shot container before swapping the app container).
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
  echo "[entrypoint] running migrations"
  php artisan migrate --force
fi

# Cache config/routes/views/events at boot for fast first request.
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# Warm Statamic stache (fast on flat-file content). Don't fail boot if it errors.
php artisan statamic:stache:warm || true

exec "$@"
