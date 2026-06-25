ssh studioguez@188.213.129.171 -p 22241 -i ~/.ssh/modus-preprod

# Set these for the session
export DEPLOY_PATH=/data/docker/instances/staging.fondationfides.ch
export SHARED_DIR=$DEPLOY_PATH/shared
export SHA7=cbaea9c
export IMAGE_TAG="preprod-sha-$SHA7"
export IMAGE_REF="ghcr.io/studio-guez/fides.website:$IMAGE_TAG"
export RELEASE_ID="$(date -u +%Y%m%dT%H%M%SZ)-$SHA7"
export RELEASE_DIR="$DEPLOY_PATH/releases/$RELEASE_ID"

# Authenticate to GHCR (only needed once, or if token expired)
echo "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" | docker login ghcr.io -u octoy --password-stdin


# Pull the new image
docker pull "$IMAGE_REF"

mkdir -p "$RELEASE_DIR"

# Get the docker/ files — two options:

# Option A: clone the repo (first time or if not present)
git clone --depth 1 --branch preprod \
  https://github.com/studio-guez/fides.website.git /tmp/fides-src
cp -r /tmp/fides-src/docker "$RELEASE_DIR/docker"
cp /tmp/fides-src/.env.example "$RELEASE_DIR/.env.example"
mkdir -p "$RELEASE_DIR/public"
cp /tmp/fides-src/public/robots.txt "$RELEASE_DIR/public/robots.txt"
cp /tmp/fides-src/public/.htaccess "$RELEASE_DIR/public/.htaccess"
rm -rf /tmp/fides-src

# Option B: subsequent deploys — copy from the previous release (faster).
# Safe when docker/compose/compose.prod.yaml and docker/prod/nginx.conf have NOT changed.
# (entrypoint.sh, app code, etc. are baked into the image — always up to date regardless.)
# cp -r $DEPLOY_PATH/current/docker "$RELEASE_DIR/docker"

# Bootstrap shared dirs + seed missing files (no-ops if already exist)
mkdir -p \
  "$SHARED_DIR/storage/app/public" "$SHARED_DIR/storage/framework/cache/data" \
  "$SHARED_DIR/storage/framework/sessions" "$SHARED_DIR/storage/framework/views" \
  "$SHARED_DIR/storage/logs" "$SHARED_DIR/content" "$SHARED_DIR/users" \
  "$SHARED_DIR/public" "$SHARED_DIR/auth" "$SHARED_DIR/database" "$SHARED_DIR/backups"

[ ! -f "$SHARED_DIR/.env" ] && cp "$RELEASE_DIR/.env.example" "$SHARED_DIR/.env" && chmod 640 "$SHARED_DIR/.env"
[ ! -f "$SHARED_DIR/public/robots.txt" ] && cp "$RELEASE_DIR/public/robots.txt" "$SHARED_DIR/public/robots.txt"
[ ! -f "$SHARED_DIR/public/.htaccess" ] && cp "$RELEASE_DIR/public/.htaccess" "$SHARED_DIR/public/.htaccess"
# Guard: the container entrypoint can create database.sqlite as a directory on a botched run.
[ -d "$SHARED_DIR/database/database.sqlite" ] && rm -rf "$SHARED_DIR/database/database.sqlite"
[ ! -f "$SHARED_DIR/database/database.sqlite" ] && touch "$SHARED_DIR/database/database.sqlite"

# Fix ownership — www-data inside containers must be able to write
# to storage, database, and content. Scoped to avoid chowning .env.
# .env is re-owned to the current user's UID:GID so the docker CLI can read it.
docker run --rm \
  -v "$SHARED_DIR:/shared" \
  --user root \
  "$IMAGE_REF" \
  sh -c "chown -R www-data:www-data /shared/storage /shared/database /shared/content /shared/users /shared/public /shared/auth /shared/backups && chmod -R g+w /shared/content && chown $(id -u):$(id -g) /shared/.env"

# DB backup
sqlite3 "$SHARED_DIR/database/database.sqlite" \
  ".backup '$SHARED_DIR/backups/db-$(date -u +%Y%m%dT%H%M%SZ).sqlite'" 2>/dev/null || true

# Migrations
docker run --rm \
  --env-file "$SHARED_DIR/.env" \
  --entrypoint php \
  -v "$SHARED_DIR/database/database.sqlite:/var/www/html/database/database.sqlite" \
  -v "$SHARED_DIR/storage:/var/www/html/storage" \
  "$IMAGE_REF" artisan migrate --force

# Flip symlink
ln -sfn "$RELEASE_DIR" "$DEPLOY_PATH/current.tmp"
mv -Tf "$DEPLOY_PATH/current.tmp" "$DEPLOY_PATH/current"

# Refresh public volume and bring up the stack
# Must stop first so nginx releases app-public, otherwise volume rm silently fails
# and the old volume (wrong UID ownership) stays, crash-looping the app container.
APP_IMAGE_TAG="$IMAGE_TAG" \
SHARED_PATH="$SHARED_DIR" \
docker compose -f "$DEPLOY_PATH/current/docker/compose/compose.prod.yaml" down 2>/dev/null || true
docker volume rm fides_app-public 2>/dev/null || true

APP_HTTP_PORT=$(grep -E '^APP_HTTP_PORT=' "$SHARED_DIR/.env" | cut -d= -f2 | tr -d '[:space:]')
APP_IMAGE_TAG="$IMAGE_TAG" \
SHARED_PATH="$SHARED_DIR" \
APP_HTTP_PORT="${APP_HTTP_PORT:-8080}" \
docker compose -f "$DEPLOY_PATH/current/docker/compose/compose.prod.yaml" up -d --remove-orphans

# On first deploy: edit .env with real values then restart
# Minimum required: APP_KEY (generate with command below), APP_URL
# docker run --rm "$IMAGE_REF" php artisan key:generate --show
nano "$SHARED_DIR/.env"
APP_IMAGE_TAG="$IMAGE_TAG" \
SHARED_PATH="$SHARED_DIR" \
docker compose -f "$DEPLOY_PATH/current/docker/compose/compose.prod.yaml" up -d --remove-orphans

# Storage (uploaded assets)
rsync -rlvz \
  --no-perms --no-times --omit-dir-times \
  -e "ssh -p 22241 -i ~/.ssh/modus-preprod" \
  storage/app/public/ \
  studioguez@188.213.129.171:/data/docker/instances/staging.fondationfides.ch/shared/storage/app/public/

# Content (entries, globals, nav trees)
rsync -rlvz \
  --no-perms --no-times --omit-dir-times \
  -e "ssh -p 22241 -i ~/.ssh/modus-preprod" \
  content/ \
  studioguez@188.213.129.171:/data/docker/instances/staging.fondationfides.ch/shared/content/

# Restore www-data ownership so Statamic can write content from the CP
docker run --rm \
  -v "$SHARED_DIR:/shared" \
  --user root \
  "$IMAGE_REF" \
  sh -c "chown -R www-data:www-data /shared/content && chmod -R g+w /shared/content"

# ── Optional: HTTP Basic Auth (preprod only) ────────────────────────────────
# The nginx container mounts $SHARED_DIR/auth/ into /etc/nginx/auth/ (read-only).
# Leave the directory empty to disable auth (nginx's glob matches nothing).
# Run these commands on the server to enable it:

# 1. Generate the htpasswd file
printf '%s:%s\n' "fides" "$(openssl passwd -apr1 'Fides26!!')" \
  > "$SHARED_DIR/auth/.htpasswd"
chmod 644 "$SHARED_DIR/auth/.htpasswd"

# 2. Create the nginx config that activates Basic Auth
cat > "$SHARED_DIR/auth/auth.conf" <<'EOF'
auth_basic "Preprod";
auth_basic_user_file /etc/nginx/auth/.htpasswd;
EOF

# 3. Reload nginx (no restart needed)
APP_IMAGE_TAG="$IMAGE_TAG" \
SHARED_PATH="$SHARED_DIR" \
docker compose -f "$DEPLOY_PATH/current/docker/compose/compose.prod.yaml" exec nginx nginx -s reload

# To disable auth: remove the files and reload
# rm "$SHARED_DIR/auth/auth.conf" "$SHARED_DIR/auth/.htpasswd"
# docker compose -f "$DEPLOY_PATH/current/docker/compose/compose.prod.yaml" exec nginx nginx -s reload
