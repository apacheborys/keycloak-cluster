<?php

declare(strict_types=1);

namespace App\Keycloak\Fixture;

use App\Entity\KeycloakFlowFixtureUser;
use App\Repository\KeycloakFlowFixtureUserRepository;
use RuntimeException;

final class SymfonyFixtureUserStore
{
    public function __construct(
        private readonly KeycloakFlowFixtureUserRepository $repository,
    ) {
    }

    public function ensureSchema(): void
    {
        if (!$this->repository->fixtureTableExists()) {
            throw new RuntimeException(
                'Fixture table "keycloak_flow_fixture_users" does not exist. '
                . 'Run "php bin/console doctrine:migrations:migrate --no-interaction".'
            );
        }
    }

    /**
     * @param list<string> $roles
     */
    public function createFixtureUser(
        string $id,
        string $runId,
        string $scenario,
        string $username,
        string $email,
        string $plainPassword,
        string $firstName,
        string $lastName,
        bool $emailVerified,
        bool $enabled,
        array $roles,
    ): FlowFixtureUserRecord {
        $entity = new KeycloakFlowFixtureUser(
            id: $id,
            runId: $runId,
            scenario: $scenario,
            username: $username,
            email: $email,
            plainPassword: $plainPassword,
            firstName: $firstName,
            lastName: $lastName,
            emailVerified: $emailVerified,
            enabled: $enabled,
            roles: $roles,
        );

        $this->repository->save($entity, true);

        return new FlowFixtureUserRecord(
            id: $entity->getId(),
            runId: $entity->getRunId(),
            scenario: $entity->getScenario(),
            username: $entity->getUsername(),
            email: $entity->getEmail(),
            plainPassword: $entity->getPlainPassword(),
            firstName: $entity->getFirstName(),
            lastName: $entity->getLastName(),
            emailVerified: $entity->isEmailVerified(),
            enabled: $entity->isEnabled(),
            roles: $entity->getRoles(),
        );
    }

    public function cleanupByRunId(string $runId): int
    {
        return $this->repository->deleteByRunId($runId);
    }
}
