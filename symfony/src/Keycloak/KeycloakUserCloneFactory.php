<?php

declare(strict_types=1);

namespace App\Keycloak;

use Apacheborys\KeycloakPhpClient\Entity\KeycloakUserInterface;
use LogicException;

final class KeycloakUserCloneFactory
{
    /**
     * @param list<string>|null $roles
     */
    public function withKeycloakId(
        KeycloakUserInterface $localUser,
        string $keycloakId,
        ?array $roles = null,
    ): KeycloakUserInterface {
        $roles ??= $localUser->getRoles();

        if ($localUser instanceof LocalUser) {
            return new LocalUser(
                username: $localUser->getUsername(),
                email: $localUser->getEmail(),
                firstName: $localUser->getFirstName(),
                lastName: $localUser->getLastName(),
                enabled: $localUser->isEnabled(),
                emailVerified: $localUser->isEmailVerified(),
                roles: $roles,
                id: $localUser->getId(),
                keycloakId: $keycloakId,
            );
        }

        if ($localUser instanceof FixtureUser) {
            return new FixtureUser(
                username: $localUser->getUsername(),
                email: $localUser->getEmail(),
                firstName: $localUser->getFirstName(),
                lastName: $localUser->getLastName(),
                enabled: $localUser->isEnabled(),
                emailVerified: $localUser->isEmailVerified(),
                roles: $roles,
                id: $localUser->getId(),
                keycloakId: $keycloakId,
            );
        }

        throw new LogicException(sprintf(
            'Unsupported local user class "%s" for cloning.',
            $localUser::class,
        ));
    }
}
