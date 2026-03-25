<?php

declare(strict_types=1);

namespace App\Keycloak\Fixture;

use PDO;
use PDOException;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class SymfonyFixtureUserStore
{
    private ?PDO $pdo = null;

    public function __construct(
        #[Autowire('%env(resolve:DATABASE_URL)%')]
        private readonly string $databaseUrl,
    ) {
    }

    public function ensureSchema(): void
    {
        $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS keycloak_flow_fixture_users (
    id UUID PRIMARY KEY,
    run_id UUID NOT NULL,
    scenario VARCHAR(64) NOT NULL,
    username VARCHAR(255) NOT NULL,
    email VARCHAR(320) NOT NULL,
    plain_password VARCHAR(255) NOT NULL,
    first_name VARCHAR(255) NOT NULL,
    last_name VARCHAR(255) NOT NULL,
    email_verified BOOLEAN NOT NULL DEFAULT FALSE,
    enabled BOOLEAN NOT NULL DEFAULT TRUE,
    roles JSONB NOT NULL DEFAULT '[]'::jsonb,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_keycloak_flow_fixture_users_run_id
    ON keycloak_flow_fixture_users (run_id);
SQL;

        $this->getPdo()->exec($sql);
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
        $statement = $this->getPdo()->prepare(
            <<<'SQL'
INSERT INTO keycloak_flow_fixture_users (
    id,
    run_id,
    scenario,
    username,
    email,
    plain_password,
    first_name,
    last_name,
    email_verified,
    enabled,
    roles
) VALUES (
    :id,
    :run_id,
    :scenario,
    :username,
    :email,
    :plain_password,
    :first_name,
    :last_name,
    :email_verified,
    :enabled,
    CAST(:roles AS jsonb)
)
SQL
        );

        if ($statement === false) {
            throw new RuntimeException('Failed to prepare fixture insert statement.');
        }

        $rolesJson = json_encode($roles, JSON_THROW_ON_ERROR);

        $statement->execute([
            'id' => $id,
            'run_id' => $runId,
            'scenario' => $scenario,
            'username' => $username,
            'email' => $email,
            'plain_password' => $plainPassword,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email_verified' => $emailVerified ? 'true' : 'false',
            'enabled' => $enabled ? 'true' : 'false',
            'roles' => $rolesJson,
        ]);

        return new FlowFixtureUserRecord(
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
    }

    public function cleanupByRunId(string $runId): int
    {
        $statement = $this->getPdo()->prepare('DELETE FROM keycloak_flow_fixture_users WHERE run_id = :run_id');
        if ($statement === false) {
            throw new RuntimeException('Failed to prepare fixture cleanup statement.');
        }

        $statement->execute(['run_id' => $runId]);

        return $statement->rowCount();
    }

    private function getPdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $parsed = parse_url($this->databaseUrl);
        if (!is_array($parsed)) {
            throw new RuntimeException('Invalid DATABASE_URL format.');
        }

        $scheme = (string) ($parsed['scheme'] ?? '');
        if ($scheme !== 'postgresql' && $scheme !== 'postgres') {
            throw new RuntimeException(sprintf('Unsupported DATABASE_URL scheme "%s". Expected postgresql.', $scheme));
        }

        $host = (string) ($parsed['host'] ?? 'localhost');
        $port = (int) ($parsed['port'] ?? 5432);
        $path = (string) ($parsed['path'] ?? '');
        $database = ltrim($path, '/');

        if ($database === '') {
            throw new RuntimeException('DATABASE_URL must include a database name.');
        }

        $username = urldecode((string) ($parsed['user'] ?? ''));
        $password = urldecode((string) ($parsed['pass'] ?? ''));

        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $database);

        try {
            $this->pdo = new PDO(
                $dsn,
                $username,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ],
            );
        } catch (PDOException $exception) {
            throw new RuntimeException('Failed to connect to Symfony fixture database.', previous: $exception);
        }

        return $this->pdo;
    }
}
