<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\KeycloakFlowFixtureUserRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: KeycloakFlowFixtureUserRepository::class)]
#[ORM\Table(name: 'keycloak_flow_fixture_users')]
#[ORM\Index(name: 'idx_keycloak_flow_fixture_users_run_id', columns: ['run_id'])]
class KeycloakFlowFixtureUser
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: Types::GUID)]
        private string $id,
        #[ORM\Column(type: Types::GUID, name: 'run_id')]
        private string $runId,
        #[ORM\Column(type: Types::STRING, length: 64)]
        private string $scenario,
        #[ORM\Column(type: Types::STRING, length: 255)]
        private string $username,
        #[ORM\Column(type: Types::STRING, length: 320)]
        private string $email,
        #[ORM\Column(type: Types::STRING, length: 255, name: 'plain_password')]
        private string $plainPassword,
        #[ORM\Column(type: Types::STRING, length: 255, name: 'first_name')]
        private string $firstName,
        #[ORM\Column(type: Types::STRING, length: 255, name: 'last_name')]
        private string $lastName,
        #[ORM\Column(type: Types::BOOLEAN, name: 'email_verified', options: ['default' => false])]
        private bool $emailVerified,
        #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
        private bool $enabled,
        #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
        private array $roles = [],
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, name: 'created_at')]
        private DateTimeImmutable $createdAt = new DateTimeImmutable(),
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getRunId(): string
    {
        return $this->runId;
    }

    public function getScenario(): string
    {
        return $this->scenario;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getPlainPassword(): string
    {
        return $this->plainPassword;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function isEmailVerified(): bool
    {
        return $this->emailVerified;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return $this->roles;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
