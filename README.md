# Keycloak Cluster (PostgreSQL, 2 nodes)

Local Keycloak 26.x cluster with two nodes, shared PostgreSQL database, and an external directory for extensions.

## Repository purpose
- This repository is intentionally built as a demonstration environment for:
  - `apacheborys/keycloak-php-client`
  - `apacheborys/symfony-keycloak-bundle`
- It provides a reproducible local setup to validate real Keycloak integration flows (user management, role management, JWT auth, custom mapper behavior).

## Compatibility notes for current library versions
- The demo is currently aligned with:
  - `apacheborys/keycloak-php-client: 0.0.17`
  - `apacheborys/symfony-keycloak-bundle: 0.0.8`
- The demo now covers the release line that adds:
  - typed Keycloak exception handling in `apacheborys/keycloak-php-client`
  - Symfony authenticator failure mapping in `apacheborys/symfony-keycloak-bundle`
- JWT verification flows now exercise the bundle authenticator path directly, including explicit handling when the authenticator rejects a token.
- `keycloak_bridge.security.expose_infrastructure_failure_status` is enabled by default in this test stand via `KEYCLOAK_BRIDGE_EXPOSE_INFRASTRUCTURE_FAILURE_STATUS=1`.
  - `1` allows infrastructure and upstream availability failures such as JWKS fetch or Keycloak outages to surface as `429` / `502` / `503`.
  - `0` forces those paths to return `401` instead, while the bundle still logs diagnostics through the configured Symfony logger.
- `KeycloakUserInterface` now separates:
  - local stable id: `getId()`
  - persisted external Keycloak id: `getKeycloakId()`
- `keycloak_bridge.callsign` is now required. In this repository it namespaces local-id data exposed to Keycloak and JWTs, for example:
  - attribute: `external-user-id`
  - JWT claim: `external_user_id`
- `KEYCLOAK_BRIDGE_BASE_URL` defaults to `http://localhost:8080` in this test stand so the real Symfony authenticator accepts the issuer published in local JWTs.
- The demo now includes a dedicated local-id fallback flow where `keycloakId` is intentionally `null` and the libraries must continue working through the local-id attribute fallback.
- Flow commands now validate their input with `symfony/validator` before making Keycloak calls. This keeps integration failures focused on library behavior instead of malformed local fixtures.

## Detailed documentation
- Full documentation index: [docs/README.md](docs/README.md)
- Use-case guides with Mermaid sequence diagrams and code examples:
  - [Use Case 1: Migrating Existing Symfony Users to Keycloak](docs/use-cases/01-migrating-existing-users-to-keycloak.md)
  - [Use Case 2: Delegating Authentication and Authorization to Keycloak](docs/use-cases/02-delegating-authentication-to-keycloak.md)
  - [Use Case 3: Local Registration with Keycloak as Source of Truth](docs/use-cases/03-local-registration-with-keycloak-source-of-truth.md)
  - [Use Case 4: JWT Identification for Protected Symfony Resources](docs/use-cases/04-jwt-identification-and-authorization.md)
  - [Use Case 5: Custom User Mapper for Advanced Domain Mapping](docs/use-cases/05-custom-user-mapper.md)
  - [Use Case 6: Role Lifecycle Automation and JWT Role Verification](docs/use-cases/06-role-management-lifecycle.md)
  - [Use Case 7: Operating Without Persisted Keycloak Id](docs/use-cases/07-local-id-fallback-without-persisted-keycloak-id.md)

## How to run
- Requirements: Docker + Docker Compose.
- Copy `.env.example` to `.env` and adjust credentials if needed.
- Start: `docker compose up -d`.
- Stop: `docker compose down`.
- Fresh clone smoke test (single command):
  - `docker compose exec symfony sh -lc 'php bin/console doctrine:migrations:migrate --no-interaction && composer run keycloak:advanced-suite'`

## Access
- Admin: `admin` / `admin` (set via `KC_BOOTSTRAP_ADMIN_*` in `.env`).
- Nginx load balancer: http://localhost:8080 (routes to both nodes)
- Direct access (if needed): http://localhost:8081, http://localhost:8082
- Symfony app: http://localhost:8000
- PostgreSQL (external): `localhost:5433` (maps to container port `5432`)
- Local SQL client examples:
  - Keycloak DB: `postgresql://keycloak:keycloak@localhost:5433/keycloak`
  - Symfony DB: `postgresql://symfony:symfony@localhost:5433/symfony`

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
  - A local proxy `127.0.0.1:8080 -> host.docker.internal:8080` can be started (controlled by `KEYCLOAK_LOCALHOST_PROXY*` env vars) so JWT verification works when Keycloak publishes `localhost:8080` in OIDC metadata.
  - PHP built-in server starts in foreground on `0.0.0.0:8000` with docroot `public/`.
- Doctrine:
  - Fixture storage now uses ORM (`Entity + Repository`) with migrations.
  - Run once on fresh install (or after dependency updates): `docker compose exec symfony php bin/console doctrine:migrations:migrate --no-interaction`
- Local bundle development:
  - `BUNDLE_PATH` -> mounts `symfony-keycloak-bundle` into `/app/symfony-keycloak-bundle` (Composer path repo).
  - `CLIENT_PATH` -> mounts `keycloak-php-client` into `/app/keycloak-php-client` (Composer path repo).

## Non-obvious implementation details
- Why `socat` is installed in `symfony.Dockerfile`:
  - Keycloak metadata may publish `issuer` / `jwks_uri` as `http://localhost:8080/...`.
  - Inside a container, `localhost` points to the container itself, not your host.
  - `symfony-entrypoint.sh` starts a local TCP proxy `127.0.0.1:8080 -> host.docker.internal:8080` (controlled by `KEYCLOAK_LOCALHOST_PROXY*`) so JWT verification works consistently.
  - To disable it, set `KEYCLOAK_LOCALHOST_PROXY=0`.
- Why Nginx startup uses `nc` wait:
  - Keycloak nodes take time to bootstrap on first run.
  - Waiting for both node ports reduces initial `502 Bad Gateway` responses.
- Why Keycloak clustering uses `TCPPING`:
  - This local setup prefers explicit static node discovery (`keycloak-1`, `keycloak-2`) on the Docker network.
  - It avoids external discovery dependencies and is deterministic for local development.
- Why `postgres-init/02-symfony-db.sh` might not run again:
  - Docker entrypoint init scripts run only when the Postgres volume is initialized for the first time.
  - If you already have an existing volume, new DB/user env changes are not re-applied automatically.
  - In that case either recreate the volume (`docker compose down -v`) or create DB/user manually.
- Why Composer scripts set `XDEBUG_MODE=off`:
  - Flow commands are used as integration checks and should run fast/noiselessly.
  - This suppresses repetitive Xdebug connection warnings when no IDE listener is active.

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

## Advanced Keycloak flows
- Role management flow (create user with initial roles -> reconcile roles -> verify in JWT -> cleanup):
  - `docker compose exec symfony composer run keycloak:role-flow`
- JWT authorization flow (create/login/verify/refresh + cleanup):
  - `docker compose exec symfony composer run keycloak:jwt-flow`
- Custom mapper flow (separate user entity mapper + JWT flow + cleanup):
  - `docker compose exec symfony composer run keycloak:mapper-flow`
- Local-id fallback flow (operate with `keycloakId=null`, verify fallback find/update/delete + callsigned JWT identifier):
  - `docker compose exec symfony composer run keycloak:local-id-fallback-flow`
- Run the full all-in-one suite (functional suite + role/jwt/mapper/local-id-fallback flows):
  - `docker compose exec symfony composer run keycloak:advanced-suite`
- Fresh install one-liner:
  - `docker compose exec symfony sh -lc 'php bin/console doctrine:migrations:migrate --no-interaction && composer run keycloak:advanced-suite'`

By default, flows clean up both:
- Keycloak resources (users, and temporary role when created by role flow)
- Symfony fixture data in PostgreSQL (`keycloak_flow_fixture_users`)

To keep data for debugging, pass `--no-cleanup` to a command.

## JWT debug endpoints (Symfony HTTP)
- Verify token:
  - `POST http://localhost:8000/api/keycloak/verify`
  - Bearer token in `Authorization` header, or JSON body: `{\"token\":\"...\"}`
- Introspect authenticated token payload:
  - `GET http://localhost:8000/api/keycloak/me`
  - Requires `Authorization: Bearer <token>`
- Authenticator-protected route:
  - `GET http://localhost:8000/api/protected/me`
  - Requires `Authorization: Bearer <token>`
  - This route goes through Symfony Security and `Apacheborys\\SymfonyKeycloakBridgeBundle\\Security\\KeycloakJwtAuthenticator`.

Use the endpoints for different purposes:
- `/api/keycloak/verify` and `/api/keycloak/me` are direct debug endpoints that call the JWT verification service without going through the Symfony firewall.
- `/api/protected/me` is the real firewall-protected route and is the endpoint to use when validating authenticator success and controlled failure responses.

## Extra mapper env vars
Set these in `.env`:
- `KEYCLOAK_BRIDGE_CALLSIGN`
- `KEYCLOAK_BRIDGE_MAPPER_REALM`
- `KEYCLOAK_BRIDGE_MAPPER_CLIENT_ID`
- `KEYCLOAK_BRIDGE_MAPPER_CLIENT_SECRET`
- `KEYCLOAK_BRIDGE_MAPPER_SCOPE`
- `KEYCLOAK_BRIDGE_MAPPER_ROLE_PREFIX`
- `KEYCLOAK_BRIDGE_MAPPER_ROLE_SUFFIX`

Recommended for custom mapper flow:
- include `roles` in `KEYCLOAK_BRIDGE_MAPPER_SCOPE` (for example: `openid profile email roles`)

Optional JWT/localhost proxy vars for Symfony container:
- `KEYCLOAK_LOCALHOST_PROXY`
- `KEYCLOAK_LOCALHOST_PROXY_PORT`
- `KEYCLOAK_LOCALHOST_PROXY_TARGET`

Keycloak infrastructure failure mapping:
- `KEYCLOAK_BRIDGE_EXPOSE_INFRASTRUCTURE_FAILURE_STATUS`
- Default: `1`
- Set `1` to expose infrastructure and upstream failures as `429` / `502` / `503`.
- Set `0` to force those failures to return `401` while still logging diagnostics.
