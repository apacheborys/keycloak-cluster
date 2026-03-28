<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\KeycloakFlowFixtureUser;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<KeycloakFlowFixtureUser>
 */
final class KeycloakFlowFixtureUserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, KeycloakFlowFixtureUser::class);
    }

    public function save(KeycloakFlowFixtureUser $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function deleteByRunId(string $runId): int
    {
        $result = $this->createQueryBuilder('fixture')
            ->delete()
            ->andWhere('fixture.runId = :runId')
            ->setParameter('runId', $runId)
            ->getQuery()
            ->execute();

        return is_int($result) ? $result : (int) $result;
    }

    public function fixtureTableExists(): bool
    {
        $schemaManager = $this->getEntityManager()->getConnection()->createSchemaManager();

        return $schemaManager->tablesExist(['keycloak_flow_fixture_users']);
    }
}
