#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

# Overlay content config files (content/**/* YAMLs) from the image onto the
# bind-mounted content/ directory. This keeps collection definitions, global-set
# schemas, and asset-container configs in sync with git on every deploy, without
# touching the CP-managed entry files that live deeper in the tree.
find .content-image -maxdepth 2 -name '*.yaml' | while read -r src; do
  rel="${src#.content-image/}"
  install -Dm644 -- "$src" "content/${rel}"
done

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
