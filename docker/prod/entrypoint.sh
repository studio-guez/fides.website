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

# Overlay shared public/ files (e.g. robots.txt) from the host if present.
# Files in $SHARED_PATH/public/ override the git-tracked defaults baked into
# the image. If the shared directory is empty or absent the image version is used.
if [ -d ".shared-public" ]; then
  find .shared-public -maxdepth 1 -type f | while read -r src; do
    rel="${src#.shared-public/}"
    echo "[entrypoint] overlaying public/${rel} from shared"
    install -Dm644 -- "$src" "public/${rel}"
  done
fi

# Ensure the public/storage -> storage/app/public symlink exists so nginx can
# serve /storage/* assets. The link is excluded from the image build context
# (.dockerignore) and gitignored, so it never ships in the image; recreate it on
# every boot against the bind-mounted storage volume. Idempotent.
ln -sfn /var/www/html/storage/app/public public/storage

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
