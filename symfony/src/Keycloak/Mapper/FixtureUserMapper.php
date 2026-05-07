<?php

declare(strict_types=1);

namespace App\Keycloak\Mapper;

use Apacheborys\KeycloakPhpClient\DTO\RoleDto;
use Apacheborys\KeycloakPhpClient\DTO\Request\Oidc\OidcTokenRequestDto;
use Apacheborys\KeycloakPhpClient\DTO\Request\Role\UserRolesDto;
use Apacheborys\KeycloakPhpClient\DTO\Request\User\AttributeValueDto;
use Apacheborys\KeycloakPhpClient\DTO\Request\User\CreateUserProfileDto;
use Apacheborys\KeycloakPhpClient\DTO\Request\User\DeleteUserDto;
use Apacheborys\KeycloakPhpClient\DTO\Request\User\UpdateUserDto;
use Apacheborys\KeycloakPhpClient\DTO\Request\User\UpdateUserProfileDto;
use Apacheborys\KeycloakPhpClient\Entity\KeycloakUserInterface;
use Apacheborys\KeycloakPhpClient\Mapper\LocalKeycloakUserBridgeMapperInterface;
use App\Keycloak\FixtureUser;
use Override;
use Ramsey\Uuid\Uuid;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class FixtureUserMapper implements LocalKeycloakUserBridgeMapperInterface
{
    public function __construct(
        #[Autowire('%env(KEYCLOAK_BRIDGE_MAPPER_REALM)%')]
        private string $realm,
        #[Autowire('%env(KEYCLOAK_BRIDGE_MAPPER_CLIENT_ID)%')]
        private string $clientId,
        #[Autowire('%env(KEYCLOAK_BRIDGE_MAPPER_CLIENT_SECRET)%')]
        private string $clientSecret,
        #[Autowire('%env(KEYCLOAK_BRIDGE_MAPPER_ROLE_PREFIX)%')]
        private string $rolePrefix,
        #[Autowire('%env(KEYCLOAK_BRIDGE_MAPPER_ROLE_SUFFIX)%')]
        private string $roleSuffix,
    ) {
    }

    #[Override]
    public function getRealm(KeycloakUserInterface $localUser): string
    {
        return $this->realm;
    }

    #[Override]
    public function getLocalUserIdAttribute(KeycloakUserInterface $localUser): AttributeValueDto
    {
        return new AttributeValueDto(
            attributeName: self::DEFAULT_LOCAL_USER_ID_ATTRIBUTE_NAME,
            attributeValue: $localUser->getId(),
        );
    }

    #[Override]
    public function prepareLocalUserForKeycloakUserCreation(KeycloakUserInterface $localUser): CreateUserProfileDto
    {
        return new CreateUserProfileDto(
            username: $localUser->getUsername(),
            email: $localUser->getEmail(),
            emailVerified: $localUser->isEmailVerified(),
            enabled: $localUser->isEnabled(),
            firstName: $localUser->getFirstName(),
            lastName: $localUser->getLastName(),
            realm: $this->realm,
            attributes: [$this->getLocalUserIdAttribute($localUser)],
        );
    }

    #[Override]
    public function prepareLocalUserRolesForKeycloakUserCreation(
        KeycloakUserInterface $localUser,
        array $availableRoles
    ): UserRolesDto {
        return new UserRolesDto(
            realm: $this->realm,
            roles: $this->resolveRoles(
                localRoles: $localUser->getRoles(),
                availableRoles: $availableRoles,
            ),
        );
    }

    #[Override]
    public function prepareLocalUserForKeycloakLoginUser(
        KeycloakUserInterface $localUser,
        string $plainPassword
    ): OidcTokenRequestDto {
        return new OidcTokenRequestDto(
            realm: $this->realm,
            clientId: $this->clientId,
            clientSecret: $this->clientSecret,
            username: $localUser->getUsername(),
            password: $plainPassword,
            // Keep scope null to preserve default Keycloak token claims used by strict JWT payload parser.
            scope: null,
        );
    }

    #[Override]
    public function prepareLocalUserForKeycloakUserDeletion(KeycloakUserInterface $localUser): DeleteUserDto
    {
        $keycloakId = $localUser->getKeycloakId();

        return new DeleteUserDto(
            realm: $this->realm,
            userId: $keycloakId !== null ? Uuid::fromString($keycloakId) : null,
            localUserId: $localUser->getId(),
        );
    }

    #[Override]
    public function prepareLocalUserDiffForKeycloakUserUpdate(
        KeycloakUserInterface $oldUserVersion,
        KeycloakUserInterface $newUserVersion
    ): UpdateUserDto {
        $keycloakId = $newUserVersion->getKeycloakId() ?? $oldUserVersion->getKeycloakId();

        return new UpdateUserDto(
            realm: $this->realm,
            profile: new UpdateUserProfileDto(
                username: $newUserVersion->getUsername(),
                email: $newUserVersion->getEmail(),
                emailVerified: $newUserVersion->isEmailVerified(),
                enabled: $newUserVersion->isEnabled(),
                firstName: $newUserVersion->getFirstName(),
                lastName: $newUserVersion->getLastName(),
                attributes: [$this->getLocalUserIdAttribute($newUserVersion)],
            ),
            userId: $keycloakId !== null ? Uuid::fromString($keycloakId) : null,
            localUserId: $newUserVersion->getId(),
        );
    }

    #[Override]
    public function prepareLocalUserRolesForKeycloakUserUpdate(
        KeycloakUserInterface $oldUserVersion,
        KeycloakUserInterface $newUserVersion,
        array $availableRoles
    ): UserRolesDto {
        return new UserRolesDto(
            realm: $this->realm,
            roles: $this->resolveRoles(
                localRoles: $newUserVersion->getRoles(),
                availableRoles: $availableRoles,
            ),
        );
    }

    #[Override]
    public function support(KeycloakUserInterface $localUser): bool
    {
        return $localUser instanceof FixtureUser;
    }

    /**
     * @param list<string> $localRoles
     * @param list<RoleDto> $availableRoles
     * @return list<RoleDto>
     */
    private function resolveRoles(array $localRoles, array $availableRoles): array
    {
        $availableByName = [];
        foreach ($availableRoles as $availableRole) {
            $availableByName[$availableRole->getName()] = $availableRole;
        }

        $resolved = [];
        foreach ($this->projectRoles(localRoles: $localRoles) as $projectedRoleName) {
            $resolved[] = $availableByName[$projectedRoleName] ?? new RoleDto(name: $projectedRoleName);
        }

        return $resolved;
    }

    /**
     * @param list<string> $localRoles
     * @return list<string>
     */
    private function projectRoles(array $localRoles): array
    {
        $projected = [];
        foreach ($localRoles as $localRole) {
            $trimmed = trim($localRole);
            if ($trimmed === '') {
                continue;
            }

            $projectedRole = $this->rolePrefix . $trimmed . $this->roleSuffix;
            $projected[$projectedRole] = true;
        }

        return array_keys($projected);
    }
}
