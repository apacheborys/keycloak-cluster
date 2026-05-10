# Documentation

This directory contains implementation-oriented documentation for the local stack and for the two demo libraries used in this repository:

- `apacheborys/keycloak-php-client`
- `apacheborys/symfony-keycloak-bundle`

The goal is to show realistic integration patterns, not only isolated API calls.

## Documentation map

- [Use Case 1: Migrating Existing Symfony Users to Keycloak](./use-cases/01-migrating-existing-users-to-keycloak.md)
- [Use Case 2: Delegating Authentication and Authorization to Keycloak](./use-cases/02-delegating-authentication-to-keycloak.md)
- [Use Case 3: Local Registration with Keycloak as Source of Truth](./use-cases/03-local-registration-with-keycloak-source-of-truth.md)
- [Use Case 4: JWT Identification for Protected Symfony Resources](./use-cases/04-jwt-identification-and-authorization.md)
- [Use Case 5: Custom User Mapper for Advanced Domain Mapping](./use-cases/05-custom-user-mapper.md)
- [Use Case 6: Role Lifecycle Automation and JWT Role Verification](./use-cases/06-role-management-lifecycle.md)
- [Use Case 7: Operating Without Persisted Keycloak Id](./use-cases/07-local-id-fallback-without-persisted-keycloak-id.md)

## Quick validation commands

Run from project root:

```bash
docker compose up -d
docker compose exec symfony php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec symfony composer run keycloak:advanced-suite
```

The suite validates:

- user creation with plain password
- user creation with hashed passwords (`argon`, `bcrypt`, `md5`)
- role update flow
- JWT verification and refresh flow
- authenticator failure response mapping
- custom mapper flow
- local-id fallback flow without persisted `keycloakId`

## Current version notes

- This repository currently targets `apacheborys/keycloak-php-client 0.0.17` and `apacheborys/symfony-keycloak-bundle 0.0.8`.
- The current demo release line covers typed Keycloak exception handling in the client and Symfony authenticator failure mapping in the bundle.
- `keycloak_bridge.security.expose_infrastructure_failure_status` is wired through `KEYCLOAK_BRIDGE_EXPOSE_INFRASTRUCTURE_FAILURE_STATUS` and defaults to `1` in this stack.
- Use `1` to let infrastructure and upstream failures return `429` / `502` / `503`; use `0` to force those paths to return `401` while keeping diagnostics in the logs.
- `getId()` is treated as the stable local identifier, while `getKeycloakId()` is the persisted external Keycloak UUID.
- `keycloak_bridge.callsign` is mandatory and is used to namespace the local-id attribute / JWT claim seen in Keycloak.

## Design principles used in examples

- Symfony app code should prefer `KeycloakServiceInterface` and related public service interfaces.
- When a library behavior gap is under validation, this demo may explicitly use other public interfaces such as `KeycloakHttpClientInterface`, but keeps that usage isolated in flow services and documents why it exists.
- Flow inputs are validated with `symfony/validator` before any Keycloak network call.
- Functional flows clean up test users and fixture records by default.
- For repeatable integration tests, fixture state is persisted in PostgreSQL with Doctrine ORM and cleaned after each run.
