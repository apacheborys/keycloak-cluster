<?php

declare(strict_types=1);

namespace App\Keycloak\Fixture;

use App\Keycloak\FixtureUser;
use App\Keycloak\LocalUser;

final readonly class FlowFixtureUserRecord
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        private string $id,
        private string $runId,
        private string $scenario,
        private string $username,
        private string $email,
        private string $plainPassword,
        private string $firstName,
        private string $lastName,
        private bool $emailVerified,
        private bool $enabled,
        private array $roles,
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

    public function toLocalUser(): LocalUser
    {
        return new LocalUser(
            username: $this->username,
            email: $this->email,
            firstName: $this->firstName,
            lastName: $this->lastName,
            enabled: $this->enabled,
            emailVerified: $this->emailVerified,
            roles: $this->roles,
        );
    }

    public function toFixtureUser(): FixtureUser
    {
        return new FixtureUser(
            username: $this->username,
            email: $this->email,
            firstName: $this->firstName,
            lastName: $this->lastName,
            enabled: $this->enabled,
            emailVerified: $this->emailVerified,
            roles: $this->roles,
        );
    }
}
