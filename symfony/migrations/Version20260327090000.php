<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260327090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create keycloak_flow_fixture_users table for keycloak flow fixtures';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('keycloak_flow_fixture_users')) {
            return;
        }

        $this->addSql(<<<'SQL'
CREATE TABLE keycloak_flow_fixture_users (
    id UUID NOT NULL,
    run_id UUID NOT NULL,
    scenario VARCHAR(64) NOT NULL,
    username VARCHAR(255) NOT NULL,
    email VARCHAR(320) NOT NULL,
    plain_password VARCHAR(255) NOT NULL,
    first_name VARCHAR(255) NOT NULL,
    last_name VARCHAR(255) NOT NULL,
    email_verified BOOLEAN DEFAULT FALSE NOT NULL,
    enabled BOOLEAN DEFAULT TRUE NOT NULL,
    roles JSONB NOT NULL,
    created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
    PRIMARY KEY(id)
)
SQL);
        $this->addSql('CREATE INDEX idx_keycloak_flow_fixture_users_run_id ON keycloak_flow_fixture_users (run_id)');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('keycloak_flow_fixture_users')) {
            return;
        }

        $this->addSql('DROP TABLE keycloak_flow_fixture_users');
    }
}
