# Use Case 5: Custom User Mapper for Advanced Domain Mapping

## When this is useful

Use a custom mapper when the default mapping is not enough, for example:

- you route different user entity classes to different realms
- you use different clients/scopes for specific user domains
- you need role projection (for example, add prefix/suffix before sending roles to Keycloak)

In this repository, `FixtureUserMapper` demonstrates this pattern.

## Sequence diagram

```mermaid
sequenceDiagram
    participant Flow as Symfony Flow Service
    participant Entity as FixtureUser (local entity)
    participant Mapper as FixtureUserMapper
    participant KS as KeycloakServiceInterface
    participant KC as Keycloak

    Flow->>KS: createUser(fixtureUser, passwordDto)
    KS->>Mapper: support(fixtureUser)?
    Mapper-->>KS: true
    KS->>Mapper: prepareLocalUserForKeycloakUserCreation(...)
    Mapper-->>KS: CreateUserProfileDto(realm, projected roles)
    KS->>KC: Create user in mapper realm/client context
    KC-->>KS: User created
    KS-->>Flow: Keycloak user
```

## Configuration example

```yaml
# config/packages/keycloak_bridge.yaml
keycloak_bridge:
  user_entities:
    'App\\Keycloak\\LocalUser':
      realm: '%env(KEYCLOAK_BRIDGE_USER_REALM)%'

    'App\\Keycloak\\FixtureUser':
      realm: '%env(KEYCLOAK_BRIDGE_MAPPER_REALM)%'
      mapper: 'App\\Keycloak\\Mapper\\FixtureUserMapper'
```

## Example: mapper skeleton

```php
<?php

declare(strict_types=1);

namespace App\Keycloak\Mapper;

use Apacheborys\KeycloakPhpClient\DTO\Request\CreateUserProfileDto;
use Apacheborys\KeycloakPhpClient\DTO\Request\DeleteUserDto;
use Apacheborys\KeycloakPhpClient\DTO\Request\OidcTokenRequestDto;
use Apacheborys\KeycloakPhpClient\DTO\Request\UpdateUserDto;
use Apacheborys\KeycloakPhpClient\Entity\KeycloakUserInterface;
use Apacheborys\KeycloakPhpClient\Mapper\LocalKeycloakUserBridgeMapperInterface;
use App\Keycloak\FixtureUser;

final readonly class FixtureUserMapper implements LocalKeycloakUserBridgeMapperInterface
{
    public function getRealm(KeycloakUserInterface $localUser): string
    {
        return 'master';
    }

    public function support(KeycloakUserInterface $localUser): bool
    {
        return $localUser instanceof FixtureUser;
    }

    public function prepareLocalUserForKeycloakUserCreation(KeycloakUserInterface $localUser, array $availableRoles): CreateUserProfileDto
    {
        // Map local roles into projected Keycloak roles
        return new CreateUserProfileDto(
            username: $localUser->getUsername(),
            email: $localUser->getEmail(),
            emailVerified: $localUser->isEmailVerified(),
            enabled: $localUser->isEnabled(),
            firstName: $localUser->getFirstName(),
            lastName: $localUser->getLastName(),
            realm: $this->getRealm($localUser),
            roles: $availableRoles,
        );
    }

    public function prepareLocalUserForKeycloakLoginUser(KeycloakUserInterface $localUser, string $plainPassword): OidcTokenRequestDto
    {
        return new OidcTokenRequestDto(
            realm: $this->getRealm($localUser),
            clientId: 'mapper-client',
            clientSecret: 'mapper-secret',
            username: $localUser->getUsername(),
            password: $plainPassword,
        );
    }

    public function prepareLocalUserForKeycloakUserDeletion(KeycloakUserInterface $localUser): DeleteUserDto
    {
        // return DeleteUserDto(...)
    }

    public function prepareLocalUserDiffForKeycloakUserUpdate(
        KeycloakUserInterface $oldUserVersion,
        KeycloakUserInterface $newUserVersion,
        array $availableRoles,
    ): UpdateUserDto {
        // return UpdateUserDto(...)
    }
}
```

## How to validate in this demo

```bash
docker compose exec symfony composer run keycloak:mapper-flow
```

Expected checks performed by the command:

- user created with custom entity mapper
- JWT issuer belongs to mapper realm
- JWT `azp` matches mapper client id
- projected mapper roles are present in JWT
- user and fixture rows are cleaned up after flow
