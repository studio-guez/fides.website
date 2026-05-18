# fides.website

Statamic 6 site for fides.website.

## Tech stack

- **CMS:** Statamic 6 (flat-file content + flat-file users)
- **Framework:** Laravel 13
- **PHP:** 8.3 (FPM)
- **DB:** SQLite (local, CI, production — bind-mounted host file in prod)
- **Local dev:** Laravel Sail (Docker)
- **Production runtime:** Docker on a VPS — `app` (php-fpm) + `nginx` containers, no Sail
- **Container registry:** GitHub Container Registry (`ghcr.io/studio-guez/fides.website`)
- **TLS:** Caddy in a separate `edge` Docker Compose stack on the VPS, with auto Let's Encrypt
- **Frontend:** Vite + Tailwind (built in CI, baked into the production image)
- **CI:** GitHub Actions, SQLite-backed
- **Deploy:** GitHub Actions → build image → push to GHCR → SSH → `docker compose up -d`

## Local development

```bash
composer install
cp .env.example .env
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate
./vendor/bin/sail npm install
./vendor/bin/sail npm run build
./vendor/bin/sail artisan statamic:make:user
```

Open <http://localhost> (front) and <http://localhost/cp> (control panel).
Mailpit UI: <http://localhost:8025>.

### Useful Sail commands

```bash
./vendor/bin/sail artisan ...   # any artisan command
./vendor/bin/sail npm run dev   # Vite dev server
./vendor/bin/sail test          # phpunit
./vendor/bin/sail shell         # bash inside the container
```

## File-based Statamic users

We use Statamic's flat-file user repository (`config/statamic/users.php` →
`'repository' => 'file'`). User account files live in `users/`. Two modes:

### Mode A — fully version-controlled (default for this repo)

- `users/` is **committed** to git and **baked into the production image**.
- Production CP cannot create or edit users persistently.
- To add a user: run `sail artisan statamic:make:user` locally, commit the
  resulting YAML, deploy.

### Mode B — editable in production

- Add `/users/` to `.gitignore`.
- Uncomment the `/srv/fides/shared/users:/var/www/html/users` bind mount in
  `docker/compose/compose.prod.yaml`.
- CP user management persists across deploys via the host bind mount.

Roles and groups (`resources/users/{roles,groups}.yaml`) are **always**
version-controlled in both modes.

## Production deployment (Docker on VPS)

There is no Sail, no `composer`, and no `npm` on the production server — only
Docker and a small TLS proxy stack.

### Layout on the VPS

```
/srv/fides/
├── current -> releases/<ts>-<sha7>     # symlink to active compose bundle
├── releases/<ts>-<sha7>/               # docker/compose/ + docker/prod/
└── shared/
    ├── .env                            # production env (chmod 640)
    ├── database/database.sqlite        # bind-mounted into app container
    ├── storage/                        # bind-mounted into app container
    ├── current-tag.txt                 # image tag currently running
    ├── last-tag.txt                    # previous tag, for rollback
    └── backups/db-*.sqlite             # nightly DB backups
```

`/srv/fides/shared/database/database.sqlite` lives on the **host filesystem**,
outside any container, outside any release directory. Image rebuilds and
rollbacks cannot touch it.

### One-time VPS setup

As root on Ubuntu 24.04:

```bash
apt update && apt install -y ca-certificates curl gnupg sqlite3 rsync
install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg \
  | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] \
  https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo $VERSION_CODENAME) stable" \
  > /etc/apt/sources.list.d/docker.list
apt update && apt install -y docker-ce docker-ce-cli containerd.io \
                             docker-buildx-plugin docker-compose-plugin

adduser --disabled-password --gecos "" deploy
usermod -aG docker deploy

sudo -u deploy mkdir -p \
  /srv/fides/{releases,shared/{database,storage,backups}}

# UID 1000 == www-data inside the production image
sudo -u deploy touch /srv/fides/shared/database/database.sqlite
sudo -u deploy sqlite3 /srv/fides/shared/database/database.sqlite \
  "PRAGMA journal_mode=WAL;"
chown -R 1000:1000 /srv/fides/shared/{database,storage}

# Production .env (copy + edit from .env.example, generate APP_KEY, etc.)
sudo -u deploy install -m 640 /dev/null /srv/fides/shared/.env
sudo -u deploy nano /srv/fides/shared/.env

# Shared external network used by the edge (TLS) stack
sudo -u deploy docker network create edge || true

# Authenticate VPS to GHCR for image pulls
echo "$GHCR_PAT" | sudo -u deploy docker login ghcr.io -u <gh-user> --password-stdin
```

Generate an `APP_KEY` once and paste it into the production `.env`:

```bash
docker run --rm ghcr.io/studio-guez/fides.website:latest \
  php artisan key:generate --show
```

### TLS / edge stack (Caddy)

Run separately in `/srv/edge/compose.yaml`:

```yaml
name: edge
services:
  caddy:
    image: lucaslorentz/caddy-docker-proxy:ci-alpine
    restart: unless-stopped
    ports: ["80:80", "443:443"]
    environment:
      CADDY_INGRESS_NETWORKS: edge
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock
      - caddy_data:/data
      - caddy_config:/config
    networks: [edge]
volumes:
  caddy_data:
  caddy_config:
networks:
  edge:
    name: edge
```

Caddy auto-discovers the `caddy: <domain>` label on the `nginx` service in the
app stack and provisions Let's Encrypt certs automatically.

### What happens on `git push` to `main`

1. `ci.yml` runs lint + tests on SQLite.
2. `deploy.yml` builds `docker/prod/Dockerfile` and pushes
   `ghcr.io/studio-guez/fides.website:sha-<sha7>` + `:latest` to GHCR.
3. CI uploads a small bundle (`docker/compose/`, `docker/prod/`) to the VPS
   via SSH and extracts it into a new release directory.
4. On the VPS:
   - SQLite is backed up with `sqlite3 .backup`.
   - The new image is `docker pull`-ed.
   - `php artisan migrate --force` runs in a one-shot container against the
     shared SQLite file.
   - The `current` symlink is flipped.
   - `docker compose ... up -d` replaces `app` and `nginx`.
   - Laravel + Statamic caches are warmed.
   - Old releases and dangling images are pruned.

### Required GitHub Actions secrets

| Secret              | Purpose                                                 |
| ------------------- | ------------------------------------------------------- |
| `SSH_HOST`          | VPS hostname/IP                                         |
| `SSH_USER`          | `deploy`                                                |
| `SSH_PORT`          | usually `22`                                            |
| `SSH_PRIVATE_KEY`   | ed25519 deploy key                                      |
| `DEPLOY_PATH`       | `/srv/fides`                                            |
| `GHCR_PULL_TOKEN`   | PAT with `read:packages`, used by the VPS to pull image |
| `COMPOSER_AUTH`     | optional JSON for private Composer packages             |

All app secrets (`APP_KEY`, mail credentials, Statamic license, etc.) live in
`/srv/fides/shared/.env` on the VPS — **never** in workflow files or git.

### Rollback

```bash
ssh deploy@example.com
PREV=$(cat /srv/fides/shared/last-tag.txt)
APP_IMAGE_TAG=$PREV docker compose \
  -f /srv/fides/current/docker/compose/compose.prod.yaml up -d

# If a schema change is involved, restore the pre-deploy DB snapshot:
# docker compose -f /srv/fides/current/docker/compose/compose.prod.yaml stop app
# cp /srv/fides/shared/backups/db-<ts>.sqlite \
#    /srv/fides/shared/database/database.sqlite
# docker compose -f /srv/fides/current/docker/compose/compose.prod.yaml start app
```

### Running artisan in production

```bash
ssh deploy@example.com
docker compose -f /srv/fides/current/docker/compose/compose.prod.yaml \
  exec app php artisan tinker
```

### SQLite backup / restore

The DB is just a host file — backups run on the host, no container involvement:

```bash
sqlite3 /srv/fides/shared/database/database.sqlite \
  ".backup '/srv/fides/shared/backups/db-$(date -u +%Y%m%dT%H%M%SZ).sqlite'"
```

Add a nightly cron (`restic backup /srv/fides/shared/backups` is a good
off-site choice).

### Troubleshooting

| Symptom                       | Fix                                                                                                                |
| ----------------------------- | ------------------------------------------------------------------------------------------------------------------ |
| `database is locked`          | Confirm WAL mode and that only one `app` container is running.                                                     |
| 502 from nginx                | `docker compose logs app` — usually a missing `.env` or wrong `DB_DATABASE` path.                                  |
| Permission denied on storage  | `sudo chown -R 1000:1000 /srv/fides/shared/{storage,database}`                                                     |
| CSS/JS 404 after deploy       | The shared `app-public` volume wasn't refreshed: `docker volume rm fides_app-public && docker compose ... up -d`.  |
| Stale opcache                 | The container is replaced every deploy; if you see staleness anyway, restart `app`.                                |
| `docker pull` fails on VPS    | Re-authenticate to GHCR: `echo $PAT \| docker login ghcr.io -u <user> --password-stdin`                            |
| Lost CP-edited users          | You're in Mode A. Commit the YAMLs and redeploy, or switch to Mode B.                                              |

## Repository layout

```
.
├── app/ bootstrap/ config/ database/ public/ resources/ routes/ storage/
├── content/                # flat-file Statamic content (versioned)
├── users/                  # flat-file Statamic users (see modes above)
├── docker/
│   ├── prod/               # Dockerfile, nginx.conf, php.ini, php-fpm.conf, entrypoint.sh
│   └── compose/
│       └── compose.prod.yaml
├── compose.yaml            # Laravel Sail (local dev only)
├── .github/workflows/
│   ├── ci.yml
│   └── deploy.yml
└── README.md
```
