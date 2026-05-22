# fides.website

Statamic 6 site for fides.website.

## Tech stack

- **CMS:** Statamic 6 (flat-file content + flat-file users)
- **Framework:** Laravel 13
- **PHP:** 8.4 (FPM)
- **DB:** SQLite (local, CI, production — bind-mounted host file in prod)
- **Local dev:** Laravel Sail (Docker)
- **Production runtime:** Docker on a VPS — `app` (php-fpm) + `nginx` (app-level web server) containers, no Sail
- **Container registry:** GitHub Container Registry (`ghcr.io/studio-guez/fides.website`)
- **TLS / public routing:** handled OUTSIDE this repository by a reverse proxy installed directly on each target server (e.g. system Nginx, Caddy, or Traefik). This project only publishes the app on a loopback port; the host-level reverse proxy terminates TLS and forwards traffic to it.
- **Frontend:** Vite + Tailwind (built in CI, baked into the production image)
- **CI:** GitHub Actions, SQLite-backed
- **Deploy:** GitHub Actions → build image → push to GHCR → SSH → `docker compose up -d`. Two environments: `preprod` branch → preproduction server, `main` branch → production server.

## Local development

1. `git clone https://github.com/Octoplus-Solutions/fides.website.git`
1. `cd fides.website/`
1. `cp .env.example .env`
1. _Verifier config, surtout conflits ports dans_ `docker-compose.yml` _et_ `.env`
1. Install Composer dependencies

```bash
docker run --rm \
    -u "$(id -u):$(id -g)" \
    -v $(pwd):/opt \
    -w /opt \
    laravelsail/php84-composer:latest \
    composer install --ignore-platform-reqs
```
1. `./vendor/bin/sail up -d`
1. `./vendor/bin/sail composer install`
1. `./vendor/bin/sail artisan key:generate`
1. `./vendor/bin/sail artisan migrate`
1. `./vendor/bin/sail npm install`
1. `./vendor/bin/sail npm run build`
1. `./vendor/bin/sail artisan statamic:make:user`

Open <http://localhost> (front) and <http://localhost/cp> (control panel).
Mailpit UI: <http://localhost:8025>.

### Useful Sail commands

```bash
./vendor/bin/sail artisan ...   # any artisan command
./vendor/bin/sail npm run dev   # Vite dev server
./vendor/bin/sail test          # phpunit
./vendor/bin/sail shell         # bash inside the container
```

## File-based Statamic users and content

Statamic uses a flat-file user repository (`config/statamic/users.php` →
`'repository' => 'file'`) and flat-file content.

**Content config files** (`content/**/*` — collection definitions, global set schemas,
asset container configs) are **tracked in git** and baked into the image; they are
not bind-mounted.

**Real content** (`content/**/**` — entries, localized globals, nav trees, etc.) and
`users/` are **not tracked in git**. They are bind-mounted from the host so CP edits
persist across deploys.

Roles and groups (`resources/users/{roles,groups}.yaml`) are version-controlled.

## Deployment architecture

This repository is **only** responsible for building and shipping an
application container image. It is **not** responsible for TLS, virtual-host
routing, or any other reverse-proxy concern — those are handled by a reverse
proxy (e.g. system Nginx, Caddy, or Traefik) installed directly on each
target server, completely outside this project.

The deployed stack contains exactly two services (see
`docker/compose/compose.prod.yaml`):

- `app`   — php-fpm 8.4 running Statamic/Laravel
- `nginx` — the app-level web server that talks to php-fpm and serves the
  built static assets out of the shared `app-public` volume

The `nginx` service publishes only on `127.0.0.1:${APP_HTTP_PORT:-8080}`. The
host's reverse proxy must forward the public domain to that loopback port.

There is no Sail, no `composer`, and no `npm` on the target servers — only
Docker, the application stack above, and the host-level reverse proxy.

### Two environments

| Branch    | GitHub Environment | Image tags pushed                                          | Where it deploys                |
| --------- | ------------------ | ---------------------------------------------------------- | ------------------------------- |
| `preprod` | `preprod`          | `preprod-sha-<sha7>`, `preprod`                            | preproduction server            |
| `main`    | `production`       | `sha-<sha7>`, `latest`                                     | production server               |
| tag `v*`  | `production`       | `sha-<sha7>`, `latest`                                     | production server               |

Each environment uses its own GitHub Environment (`preprod` / `production`)
to store secrets. Production secrets are never visible to the preprod job
and vice versa. Both deploys reuse a single build job (`build-image`) so the
image is built once per push; only the SSH/deploy step is split per
environment.

`workflow_dispatch` accepts a `target` input (`preprod` or `production`) for
one-off manual deploys.

### Layout on each target server

```
$DEPLOY_PATH/                            # e.g. /srv/fides (preprod and prod use separate hosts and may use separate paths)
├── current -> releases/<ts>-<sha7>     # symlink to active compose bundle
├── releases/<ts>-<sha7>/               # docker/compose/ + docker/prod/
└── shared/
    ├── .env                            # environment-specific env (chmod 640)
    ├── database/database.sqlite        # bind-mounted into app container
    ├── storage/                        # bind-mounted into app container
    ├── content/                        # real-content sub-dirs bind-mounted (CP-editable)
    │                                   # config YAMLs (content/**/*) live in the image
    ├── users/                          # bind-mounted into app container (CP-editable)
    ├── current-tag.txt                 # image tag currently running
    ├── last-tag.txt                    # previous tag, for rollback
    └── backups/db-*.sqlite             # nightly DB backups
```

`$DEPLOY_PATH/shared/database/database.sqlite` lives on the **host
filesystem**, outside any container, outside any release directory. Image
rebuilds and rollbacks cannot touch it.

### One-time server setup (per environment)

Do this once on **each** target server (preproduction and production are
separate hosts). Replace `/srv/fides` with whatever you set as `DEPLOY_PATH`
in that environment's secrets if different.

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
  /srv/fides/{releases,shared/{database,storage,backups,content,users}}

# UID 1000 == www-data inside the production image
sudo -u deploy touch /srv/fides/shared/database/database.sqlite
sudo -u deploy sqlite3 /srv/fides/shared/database/database.sqlite \
  "PRAGMA journal_mode=WAL;"
chown -R 1000:1000 /srv/fides/shared/{database,storage,content,users}

# Environment-specific .env (copy + edit from .env.example, generate APP_KEY, etc.)
sudo -u deploy install -m 640 /dev/null /srv/fides/shared/.env
sudo -u deploy nano /srv/fides/shared/.env

# Authenticate the server to GHCR for image pulls
echo "$GHCR_PAT" | sudo -u deploy docker login ghcr.io -u <gh-user> --password-stdin
```

Generate an `APP_KEY` once and paste it into the environment's `.env`:

```bash
docker run --rm ghcr.io/studio-guez/fides.website:latest \
  php artisan key:generate --show
```

### Host-level reverse proxy (out of scope for this repo)

The application stack only exposes `127.0.0.1:${APP_HTTP_PORT:-8080}`. TLS
termination, HTTP→HTTPS redirection, hostname routing, and certificate
management must be configured **on the host**, completely outside this
repository.

Any standard reverse proxy installed on the server works (system `nginx`,
`caddy`, `traefik`, …). It only needs to forward the public domain to that
loopback port. Example sketches:

- system **nginx**: a `server { listen 443 ssl; … proxy_pass http://127.0.0.1:8080; }` block per environment
- system **Caddy**: `preprod.example.com { reverse_proxy 127.0.0.1:8080 }`

If `8080` collides with something else on a given server, set
`APP_HTTP_PORT` in that environment's shell or in `/srv/fides/shared/.env`
before bringing the stack up.

### What happens on `git push`

1. `ci.yml` runs lint + tests on SQLite (for both `main` and `preprod`, plus PRs).
2. `deploy.yml`:
   - `build-image` builds `docker/prod/Dockerfile` once and pushes to GHCR.
     - `preprod` branch → tags `preprod-sha-<sha7>` and `preprod`
     - `main` branch (or `v*` tag) → tags `sha-<sha7>` and `latest`
   - `deploy-preprod` runs **only** for `preprod` (uses the `preprod` GitHub Environment).
   - `deploy-production` runs **only** for `main` / `v*` (uses the `production` GitHub Environment).
3. The chosen deploy job uploads the small compose bundle (`docker/compose/`,
   `docker/prod/`) to its target server via SSH and extracts it into a new
   release directory.
4. On the target server:
   - SQLite is backed up with `sqlite3 .backup`.
   - The new image is `docker pull`-ed.
   - `php artisan migrate --force` runs in a one-shot container against the
     shared SQLite file.
   - The `current` symlink is flipped.
   - `docker compose ... up -d` replaces `app` and `nginx`.
   - Laravel + Statamic caches are warmed.
   - Old releases and dangling images are pruned.

### Required GitHub Actions secrets

Secrets are scoped to **GitHub Environments** so that the preproduction
deploy job cannot read production secrets and vice versa. Configure each
environment under **Settings → Environments → `preprod`** and
**Settings → Environments → `production`** with the same key names but the
environment-appropriate values:

| Secret              | Scope                 | Purpose                                                 |
| ------------------- | --------------------- | ------------------------------------------------------- |
| `SSH_HOST`          | per environment       | Target server hostname/IP                               |
| `SSH_USER`          | per environment       | usually `deploy`                                        |
| `SSH_PORT`          | per environment       | usually `22`                                            |
| `SSH_PRIVATE_KEY`   | per environment       | ed25519 deploy key authorised on that server only       |
| `DEPLOY_PATH`       | per environment       | e.g. `/srv/fides`                                       |
| `GHCR_PULL_TOKEN`   | per environment       | PAT with `read:packages`, used by the server to pull    |
| `GHCR_PULL_USER`    | per environment (opt) | GHCR username for the pull token (defaults to actor)    |
| `COMPOSER_AUTH`     | repository (optional) | JSON for private Composer packages, used at build time  |

All app secrets (`APP_KEY`, mail credentials, Statamic license, etc.) live
in `$DEPLOY_PATH/shared/.env` on each target server — **never** in workflow
files or git. Use a different `APP_KEY` and different external credentials
per environment.

### Running the production image locally

You can smoke-test the built image without any reverse proxy:

```bash
docker run --rm -p 8080:80 \
  -v $(pwd)/database/database.sqlite:/var/www/html/database/database.sqlite \
  ghcr.io/studio-guez/fides.website:latest
```

Because the production image runs php-fpm (not a full web server) on its
own, the loopback test above is mostly useful as a sanity check that the
image boots, runs migrations and caches. For a full local end-to-end test of
the compose stack, run the compose file against a throwaway shared dir:

```bash
mkdir -p /tmp/fides-shared/{database,storage,content,users,backups}
touch /tmp/fides-shared/database/database.sqlite
cp .env.example /tmp/fides-shared/.env   # then edit APP_KEY etc.

APP_IMAGE_TAG=latest \
DEPLOY_PATH=$(pwd) \
SHARED_PATH=/tmp/fides-shared \
APP_HTTP_PORT=8080 \
docker compose -f docker/compose/compose.prod.yaml up
```

Then browse to <http://127.0.0.1:8080>.


### Rollback

```bash
ssh deploy@<server>
PREV=$(cat /srv/fides/shared/last-tag.txt)
APP_IMAGE_TAG=$PREV \
DEPLOY_PATH=/srv/fides \
SHARED_PATH=/srv/fides/shared \
docker compose -f /srv/fides/current/docker/compose/compose.prod.yaml up -d

# If a schema change is involved, restore the pre-deploy DB snapshot:
# docker compose -f /srv/fides/current/docker/compose/compose.prod.yaml stop app
# cp /srv/fides/shared/backups/db-<ts>.sqlite \
#    /srv/fides/shared/database/database.sqlite
# docker compose -f /srv/fides/current/docker/compose/compose.prod.yaml start app
```

### Running artisan on a deployed server

```bash
ssh deploy@<server>
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
| 502 from the host reverse proxy | `docker compose logs app` — usually a missing `.env` or wrong `DB_DATABASE` path. Also check the host proxy is forwarding to `127.0.0.1:${APP_HTTP_PORT:-8080}`. |
| Permission denied on storage  | `sudo chown -R 1000:1000 /srv/fides/shared/{storage,database}`                                                     |
| CSS/JS 404 after deploy       | The shared `app-public` volume wasn't refreshed: `docker volume rm fides_app-public && docker compose ... up -d`.  |
| Stale opcache                 | The container is replaced every deploy; if you see staleness anyway, restart `app`.                                |
| `docker pull` fails on server | Re-authenticate to GHCR: `echo $PAT \| docker login ghcr.io -u <user> --password-stdin`                            |
| Lost CP-edited users/content  | Check that the content sub-dirs (`collections/pages`, `globals/fr`, `trees/collections`) and `users/` are bind-mounted on the host and owned by UID 1000. |

## Repository layout

```
.
├── app/ bootstrap/ config/ database/ public/ resources/ routes/ storage/
├── content/                # config YAMLs versioned; entry dirs gitignored + bind-mounted
├── users/                  # flat-file Statamic users (host bind-mount, not versioned)
├── docker/
│   ├── prod/               # Dockerfile, nginx.conf, php.ini, php-fpm.conf, entrypoint.sh
│   └── compose/
│       └── compose.prod.yaml
├── compose.yaml            # Laravel Sail (local dev only)
├── .github/
│   ├── actions/
│   │   └── remote-deploy/  # composite action: SSH + deploy a built image
│   └── workflows/
│       ├── ci.yml
│       └── deploy.yml      # build once, then deploy-preprod OR deploy-production
└── README.md
```
