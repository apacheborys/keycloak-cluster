# Keycloak Cluster (PostgreSQL, 2 nodes)

Local Keycloak 26.x cluster with two nodes, shared PostgreSQL database, and an external directory for extensions.

## How to run
- Requirements: Docker + Docker Compose.
- Copy `.env.example` to `.env` and adjust credentials if needed.
- Start: `docker compose up -d`.
- Stop: `docker compose down`.

## Access
- Admin: `admin` / `admin` (set via `KC_BOOTSTRAP_ADMIN_*` in `.env`).
- Nginx load balancer: http://localhost:8080 (routes to both nodes)
- Direct access (if needed): http://localhost:8081, http://localhost:8082
- Symfony app (once you start a server inside the container): http://localhost:8000

## Extensions
- Place your JARs/extension folders into `extensions/` (mounted to `/opt/keycloak/providers` on both nodes).
- After changing `extensions/`: `docker compose restart keycloak-1 keycloak-2`.

## Cluster notes
- Cache stack `tcp` with TCPPING discovery: nodes communicate directly over the Docker network.
- Starting with plain `start`; hostname strict modes are disabled for local convenience. Hostname is set via `KC_HOSTNAME_URL` / `KC_HOSTNAME_ADMIN_URL` for the reverse proxy on :8080.
- Nginx uses `least_conn` balancing.
- Nginx waits until both Keycloak nodes open port 8080 (simple `nc` wait) to avoid early 502s.
- If you see “HTTPS required” from Keycloak when using HTTP, set Realm Settings → Require SSL to `none` (or run `kcadm.sh update realms/master -s sslRequired=none` inside a Keycloak container).

## Symfony service
- Image built from `symfony.Dockerfile` (PHP 8.4 CLI with Composer, Symfony CLI, pdo_pgsql).
- Code lives in `./symfony` (mounted into the container).
- Database for Symfony uses the same Postgres server but a separate DB (`POSTGRES_DB_SYM`), created on first init via `postgres-init/02-symfony-db.sh`.
- Auto-bootstrap on container start:
  - If `composer.json` is missing in `./symfony`, a Symfony skeleton is created.
  - If `vendor/` is missing, `composer install` runs.
  - PHP built-in server starts in foreground on `0.0.0.0:8000` with docroot `public/`.
- Local bundle development:
  - `BUNDLE_PATH` -> mounts `symfony-keycloak-bundle` into `/app/symfony-keycloak-bundle` (Composer path repo).
  - `CLIENT_PATH` -> mounts `keycloak-php-client` into `/app/keycloak-php-client` (Composer path repo).

## Xdebug (VS Code)
- Rebuild and restart the Symfony container after changes:
  - `docker compose build symfony`
  - `docker compose up -d symfony`
- Xdebug listens on port `9003` and connects back to your host (uses `host.docker.internal`).
- Example VS Code launch config:
  ```json
  {
    "version": "0.2.0",
    "configurations": [
      {
        "name": "Listen for Xdebug",
        "type": "php",
        "request": "launch",
        "port": 9003,
        "pathMappings": {
          "/app": "${workspaceFolder}/symfony"
        }
      }
    ]
  }
  ```

## Keycloak functional checks
- Plain password flow:
  - `docker compose exec symfony php bin/console keycloak:create-user-with-plain-password username email@example.com StrongPass123 --first-name=John --last-name=Doe --email-verified`
- Hashed password flow:
  - `docker compose exec symfony php bin/console keycloak:create-user-with-hashed-password username email@example.com StrongPass123 argon --first-name=John --last-name=Doe --email-verified`
  - Supported algorithms: `argon`, `bcrypt`, `md5`.
- Full suite (plain + all hashed algorithms) with step-by-step output:
  - `docker compose exec symfony composer run keycloak:functional-suite`
