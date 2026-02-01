<?php

declare(strict_types=1);

namespace App\Keycloak;

use Apacheborys\KeycloakPhpClient\Entity\KeycloakUserInterface;
use DateTimeImmutable;
use DateTimeInterface;
use Override;

final class LocalUser implements KeycloakUserInterface
{
    /**
     * @param string[] $roles
     */
    public function __construct(
        private string $username,
        private string $email,
        private string $firstName = '',
        private string $lastName = '',
        private bool $enabled = true,
        private bool $emailVerified = false,
        private array $roles = [],
        ?string $id = null,
        ?DateTimeImmutable $createdAt = null,
    ) {
        $this->id = $id ?? bin2hex(random_bytes(16));
        $this->createdAt = $createdAt ?? new DateTimeImmutable();
    }

    private string $id;

    private DateTimeImmutable $createdAt;

    #[Override]
    public function getId(): string
    {
        return $this->id;
    }

    #[Override]
    public function getUsername(): string
    {
        return $this->username;
    }

    #[Override]
    public function getEmail(): string
    {
        return $this->email;
    }

    #[Override]
    public function isEmailVerified(): bool
    {
        return $this->emailVerified;
    }

    #[Override]
    public function getFirstName(): string
    {
        return $this->firstName;
    }

    #[Override]
    public function getLastName(): string
    {
        return $this->lastName;
    }

    /**
     * @return string[]
     */
    #[Override]
    public function getRoles(): array
    {
        return $this->roles;
    }

    #[Override]
    public function getCreatedAt(): DateTimeInterface
    {
        return $this->createdAt;
    }

    #[Override]
    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
