<?php

declare(strict_types=1);

namespace App\Keycloak;

use Apacheborys\KeycloakPhpClient\Entity\KeycloakUser;

final readonly class RoleManagementFlowResult
{
    /**
     * @param list<string> $initialRoles
     * @param list<string> $updatedRoles
     */
    public function __construct(
        private KeycloakUser $createdUser,
        private KeycloakUser $updatedUser,
        private array $initialRoles,
        private array $updatedRoles,
        private bool $updatedRolesDetectedInJwt,
        private bool $removedRolesAbsentInJwt,
    ) {
    }

    public function getCreatedUser(): KeycloakUser
    {
        return $this->createdUser;
    }

    public function getUpdatedUser(): KeycloakUser
    {
        return $this->updatedUser;
    }

    /**
     * @return list<string>
     */
    public function getInitialRoles(): array
    {
        return $this->initialRoles;
    }

    /**
     * @return list<string>
     */
    public function getUpdatedRoles(): array
    {
        return $this->updatedRoles;
    }

    public function areUpdatedRolesDetectedInJwt(): bool
    {
        return $this->updatedRolesDetectedInJwt;
    }

    public function areRemovedRolesAbsentInJwt(): bool
    {
        return $this->removedRolesAbsentInJwt;
    }
}
