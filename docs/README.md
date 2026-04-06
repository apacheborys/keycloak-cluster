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
- [Use Case 6: Role Lifecycle Automation via `KeycloakServiceInterface`](./use-cases/06-role-management-lifecycle.md)

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
- custom mapper flow

## Design principles used in examples

- Symfony app code communicates with Keycloak through `KeycloakServiceInterface` and related public service interfaces.
- Application code does not use low-level HTTP client internals directly.
- Functional flows clean up test users and fixture records by default.
- For repeatable integration tests, fixture state is persisted in PostgreSQL with Doctrine ORM and cleaned after each run.
