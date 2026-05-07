<?php

declare(strict_types=1);

namespace App\Keycloak;

use Apacheborys\KeycloakPhpClient\DTO\PasswordDto;
use Apacheborys\KeycloakPhpClient\DTO\RoleDto;
use Apacheborys\KeycloakPhpClient\DTO\Request\Role\AssignUserRolesDto;
use Apacheborys\KeycloakPhpClient\DTO\Request\Role\CreateRoleDto;
use Apacheborys\KeycloakPhpClient\DTO\Request\Role\GetRolesDto;
use Apacheborys\KeycloakPhpClient\DTO\Request\Role\GetUserAvailableRolesDto;
use Apacheborys\KeycloakPhpClient\Entity\JsonWebToken;
use Apacheborys\KeycloakPhpClient\Http\KeycloakHttpClientInterface;
use Apacheborys\KeycloakPhpClient\Service\KeycloakServiceInterface;
use LogicException;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

final readonly class KeycloakRoleManagementFlowService
{
    public function __construct(
        private KeycloakServiceInterface $keycloakService,
        private KeycloakHttpClientInterface $httpClient,
        #[Autowire('%env(KEYCLOAK_BRIDGE_CALLSIGN)%')]
        private string $callsign,
        #[Autowire('%env(KEYCLOAK_BRIDGE_USER_REALM)%')]
        private string $userRealm,
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
                id: $localUser->getId(),
                keycloakId: $createdUser->getKeycloakId(),
            );

            $newUserVersion = new LocalUser(
                username: $localUser->getUsername(),
                email: $localUser->getEmail(),
                firstName: $localUser->getFirstName(),
                lastName: $localUser->getLastName(),
                enabled: $localUser->isEnabled(),
                emailVerified: $localUser->isEmailVerified(),
                roles: $normalizedUpdatedRoles,
                id: $localUser->getId(),
                keycloakId: $createdUser->getKeycloakId(),
            );

            $this->synchronizeRoles(
                keycloakUserId: $createdUser->getKeycloakId(),
                currentRoles: $initialRoles,
                desiredRoles: $normalizedUpdatedRoles,
            );
            $updatedUser = $this->keycloakService->findUser($newUserVersion);

            $this->report($reportStep, 3, 'Login user and inspect JWT roles');
            $loginResult = $this->keycloakService->loginUser(
                user: $newUserVersion,
                plainPassword: $plainPassword,
            );
            $jwtRoles = $this->extractRolesFromJwt($loginResult->getAccessToken());
            $expectedUpdatedJwtRoles = $this->projectRolesForJwt($normalizedUpdatedRoles);

            $updatedRolesDetectedInJwt = $this->allRolesPresent(
                expectedRoles: $expectedUpdatedJwtRoles,
                actualRoles: $jwtRoles,
            );
            if (!$updatedRolesDetectedInJwt) {
                throw new LogicException(
                    sprintf(
                        'Updated roles are not fully reflected in JWT. Expected: [%s], actual: [%s].',
                        implode(', ', $expectedUpdatedJwtRoles),
                        implode(', ', $jwtRoles),
                    )
                );
            }

            $rolesRemovedByUpdate = array_values(array_diff($initialRoles, $normalizedUpdatedRoles));
            $removedRolesAbsentInJwt = !$this->anyRolePresent(
                expectedRoles: $this->projectRolesForJwt($rolesRemovedByUpdate),
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
                            id: $localUser->getId(),
                            keycloakId: $createdUser->getKeycloakId(),
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

    /**
     * @param list<string> $roles
     * @return list<string>
     */
    private function projectRolesForJwt(array $roles): array
    {
        $projected = [];
        $normalizedCallsign = rtrim(trim($this->callsign), '.');

        foreach ($this->normalizeRoles($roles) as $role) {
            $projected[$normalizedCallsign . '.' . $role] = true;
        }

        return array_keys($projected);
    }

    private function report(?callable $reportStep, int $stepNumber, string $message): void
    {
        if ($reportStep !== null) {
            $reportStep($stepNumber, $message);
        }
    }

    /**
     * @param list<string> $currentRoles
     * @param list<string> $desiredRoles
     */
    private function synchronizeRoles(string $keycloakUserId, array $currentRoles, array $desiredRoles): void
    {
        $userId = Uuid::fromString($keycloakUserId);
        $availableRoles = $this->httpClient->getRoles(
            dto: new GetRolesDto(realm: $this->userRealm),
        );

        $projectedCurrentRoles = $this->projectRolesForJwt($currentRoles);
        $projectedDesiredRoles = $this->projectRolesForJwt($desiredRoles);
        $availableRoles = $this->ensureRolesExist(
            desiredRoleNames: $projectedDesiredRoles,
            availableRoles: $availableRoles,
        );

        $roleNamesToAssign = array_values(array_diff($projectedDesiredRoles, $projectedCurrentRoles));
        if ($roleNamesToAssign !== []) {
            $availableForUser = $this->httpClient->getAvailableUserRoles(
                dto: new GetUserAvailableRolesDto(
                    realm: $this->userRealm,
                    userId: $userId,
                ),
            );
            $rolesToAssign = $this->resolveRolesByName(
                roleNames: $roleNamesToAssign,
                availableRoles: $availableForUser,
                strict: true,
            );

            if ($rolesToAssign !== []) {
                $this->httpClient->assignRolesToUser(
                    dto: new AssignUserRolesDto(
                        realm: $this->userRealm,
                        userId: $userId,
                        roles: $rolesToAssign,
                    ),
                );
            }
        }

        $roleNamesToUnassign = array_values(array_diff($projectedCurrentRoles, $projectedDesiredRoles));
        if ($roleNamesToUnassign === []) {
            return;
        }

        $rolesToUnassign = $this->resolveRolesByName(
            roleNames: $roleNamesToUnassign,
            availableRoles: $availableRoles,
            strict: false,
        );
        if ($rolesToUnassign === []) {
            return;
        }

        $this->httpClient->unassignRolesFromUser(
            dto: new AssignUserRolesDto(
                realm: $this->userRealm,
                userId: $userId,
                roles: $rolesToUnassign,
            ),
        );
    }

    /**
     * @param list<string> $desiredRoleNames
     * @param list<RoleDto> $availableRoles
     * @return list<RoleDto>
     */
    private function ensureRolesExist(array $desiredRoleNames, array $availableRoles): array
    {
        $availableByName = [];
        foreach ($availableRoles as $availableRole) {
            $availableByName[$availableRole->getName()] = true;
        }

        $hasCreatedRoles = false;
        foreach ($desiredRoleNames as $desiredRoleName) {
            if (isset($availableByName[$desiredRoleName])) {
                continue;
            }

            $this->httpClient->createRole(
                dto: new CreateRoleDto(
                    realm: $this->userRealm,
                    role: new RoleDto(name: $desiredRoleName),
                ),
            );
            $hasCreatedRoles = true;
        }

        if (!$hasCreatedRoles) {
            return $availableRoles;
        }

        return $this->httpClient->getRoles(
            dto: new GetRolesDto(realm: $this->userRealm),
        );
    }

    /**
     * @param list<string> $roleNames
     * @param list<RoleDto> $availableRoles
     * @return list<RoleDto>
     */
    private function resolveRolesByName(array $roleNames, array $availableRoles, bool $strict): array
    {
        $availableByName = [];
        foreach ($availableRoles as $availableRole) {
            $availableByName[$availableRole->getName()] = $availableRole;
        }

        $resolvedRoles = [];
        foreach ($roleNames as $roleName) {
            $resolvedRole = $availableByName[$roleName] ?? null;
            if ($resolvedRole instanceof RoleDto) {
                $resolvedRoles[] = $resolvedRole;
                continue;
            }

            if ($strict) {
                throw new LogicException(sprintf(
                    'Role "%s" cannot be resolved in Keycloak available roles.',
                    $roleName,
                ));
            }
        }

        return $resolvedRoles;
    }
}
