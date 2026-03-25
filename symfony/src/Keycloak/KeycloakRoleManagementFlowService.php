<?php

declare(strict_types=1);

namespace App\Keycloak;

use Apacheborys\KeycloakPhpClient\DTO\PasswordDto;
use Apacheborys\KeycloakPhpClient\Entity\JsonWebToken;
use Apacheborys\KeycloakPhpClient\Service\KeycloakServiceInterface;
use LogicException;
use RuntimeException;
use Throwable;

final readonly class KeycloakRoleManagementFlowService
{
    public function __construct(
        private KeycloakServiceInterface $keycloakService,
    ) {
    }

    /**
     * @param list<string> $updatedRoles
     */
    public function run(
        LocalUser $localUser,
        string $plainPassword,
        array $updatedRoles,
        bool $cleanup = true,
        ?callable $reportStep = null,
    ): RoleManagementFlowResult {
        $createdUser = null;
        $flowError = null;
        $result = null;

        $initialRoles = $this->normalizeRoles($localUser->getRoles());
        $normalizedUpdatedRoles = $this->normalizeRoles($updatedRoles);

        try {
            $this->report($reportStep, 1, 'Create user with initial roles');
            $createdUser = $this->keycloakService->createUser(
                localUser: $localUser,
                passwordDto: new PasswordDto(plainPassword: $plainPassword),
            );

            $this->report($reportStep, 2, 'Update user roles through KeycloakServiceInterface');
            $oldUserVersion = new LocalUser(
                username: $localUser->getUsername(),
                email: $localUser->getEmail(),
                firstName: $localUser->getFirstName(),
                lastName: $localUser->getLastName(),
                enabled: $localUser->isEnabled(),
                emailVerified: $localUser->isEmailVerified(),
                roles: $initialRoles,
                id: $createdUser->getId(),
            );

            $newUserVersion = new LocalUser(
                username: $localUser->getUsername(),
                email: $localUser->getEmail(),
                firstName: $localUser->getFirstName(),
                lastName: $localUser->getLastName(),
                enabled: $localUser->isEnabled(),
                emailVerified: $localUser->isEmailVerified(),
                roles: $normalizedUpdatedRoles,
                id: $createdUser->getId(),
            );

            $updatedUser = $this->keycloakService->updateUser(
                oldUserVersion: $oldUserVersion,
                newUserVersion: $newUserVersion,
            );

            $this->report($reportStep, 3, 'Login user and inspect JWT roles');
            $loginResult = $this->keycloakService->loginUser(
                user: $newUserVersion,
                plainPassword: $plainPassword,
            );
            $jwtRoles = $this->extractRolesFromJwt($loginResult->getAccessToken());

            $updatedRolesDetectedInJwt = $this->allRolesPresent(
                expectedRoles: $normalizedUpdatedRoles,
                actualRoles: $jwtRoles,
            );
            if (!$updatedRolesDetectedInJwt) {
                throw new LogicException(
                    sprintf(
                        'Updated roles are not fully reflected in JWT. Expected: [%s], actual: [%s].',
                        implode(', ', $normalizedUpdatedRoles),
                        implode(', ', $jwtRoles),
                    )
                );
            }

            $rolesRemovedByUpdate = array_values(array_diff($initialRoles, $normalizedUpdatedRoles));
            $removedRolesAbsentInJwt = !$this->anyRolePresent(
                expectedRoles: $rolesRemovedByUpdate,
                actualRoles: $jwtRoles,
            );
            if (!$removedRolesAbsentInJwt) {
                throw new LogicException(
                    sprintf(
                        'Some removed roles are still present in JWT: [%s].',
                        implode(', ', $rolesRemovedByUpdate),
                    )
                );
            }

            $result = new RoleManagementFlowResult(
                createdUser: $createdUser,
                updatedUser: $updatedUser,
                initialRoles: $initialRoles,
                updatedRoles: $normalizedUpdatedRoles,
                updatedRolesDetectedInJwt: $updatedRolesDetectedInJwt,
                removedRolesAbsentInJwt: $removedRolesAbsentInJwt,
            );
        } catch (Throwable $exception) {
            $flowError = $exception;
        }

        if ($cleanup) {
            try {
                if ($createdUser !== null) {
                    $this->report($reportStep, 4, 'Cleanup: delete user');
                    $this->keycloakService->deleteUser(
                        new LocalUser(
                            username: $localUser->getUsername(),
                            email: $localUser->getEmail(),
                            firstName: $localUser->getFirstName(),
                            lastName: $localUser->getLastName(),
                            enabled: $localUser->isEnabled(),
                            emailVerified: $localUser->isEmailVerified(),
                            roles: $normalizedUpdatedRoles,
                            id: $createdUser->getId(),
                        )
                    );
                }
            } catch (Throwable $cleanupException) {
                if ($flowError instanceof Throwable) {
                    throw new RuntimeException(
                        sprintf(
                            'Role-management flow failed and cleanup failed. Flow error: %s. Cleanup error: %s',
                            $flowError->getMessage(),
                            $cleanupException->getMessage(),
                        ),
                        previous: $flowError,
                    );
                }

                throw $cleanupException;
            }
        }

        if ($flowError instanceof Throwable) {
            throw $flowError;
        }

        if (!$result instanceof RoleManagementFlowResult) {
            throw new RuntimeException('Role-management flow finished without result.');
        }

        return $result;
    }

    /**
     * @param list<string> $expectedRoles
     * @param list<string> $actualRoles
     */
    private function allRolesPresent(array $expectedRoles, array $actualRoles): bool
    {
        if ($expectedRoles === []) {
            return true;
        }

        $actualMap = array_fill_keys($actualRoles, true);
        foreach ($expectedRoles as $expectedRole) {
            if (!isset($actualMap[$expectedRole])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $expectedRoles
     * @param list<string> $actualRoles
     */
    private function anyRolePresent(array $expectedRoles, array $actualRoles): bool
    {
        if ($expectedRoles === []) {
            return false;
        }

        $actualMap = array_fill_keys($actualRoles, true);
        foreach ($expectedRoles as $expectedRole) {
            if (isset($actualMap[$expectedRole])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function extractRolesFromJwt(JsonWebToken $jwt): array
    {
        $roles = [];

        foreach ($jwt->getPayload()->getRealmAccess()['roles'] as $realmRole) {
            if (is_string($realmRole) && trim($realmRole) !== '') {
                $roles[trim($realmRole)] = true;
            }
        }

        foreach ($jwt->getPayload()->getResourceAccess() as $resourceAccess) {
            foreach ($resourceAccess['roles'] as $resourceRole) {
                if (is_string($resourceRole) && trim($resourceRole) !== '') {
                    $roles[trim($resourceRole)] = true;
                }
            }
        }

        return array_keys($roles);
    }

    /**
     * @param list<string> $roles
     * @return list<string>
     */
    private function normalizeRoles(array $roles): array
    {
        $normalized = [];
        foreach ($roles as $role) {
            $trimmedRole = trim($role);
            if ($trimmedRole === '') {
                continue;
            }

            $normalized[$trimmedRole] = true;
        }

        return array_keys($normalized);
    }

    private function report(?callable $reportStep, int $stepNumber, string $message): void
    {
        if ($reportStep !== null) {
            $reportStep($stepNumber, $message);
        }
    }
}
