<?php

declare(strict_types=1);

namespace App\Keycloak;

use Apacheborys\KeycloakPhpClient\Entity\KeycloakUserInterface;
use DateTimeImmutable;
use DateTimeInterface;
use Override;
use Symfony\Component\Validator\Constraints as Assert;

final class FixtureUser implements KeycloakUserInterface
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        #[Assert\NotBlank(message: 'Username must not be blank.')]
        private string $username,
        #[Assert\NotBlank(message: 'Email must not be blank.')]
        #[Assert\Email(message: 'Email must be a valid email address.')]
        private string $email,
        private string $firstName = 'Fixture',
        private string $lastName = 'User',
        private bool $enabled = true,
        private bool $emailVerified = true,
        #[Assert\All([
            new Assert\NotBlank(message: 'Role names must not be blank.'),
        ])]
        private array $roles = [],
        ?string $id = null,
        ?string $keycloakId = null,
        ?DateTimeImmutable $createdAt = null,
    ) {
        $this->id = $id ?? bin2hex(random_bytes(16));
        $this->keycloakId = $keycloakId;
        $this->createdAt = $createdAt ?? new DateTimeImmutable();
    }

    #[Assert\NotBlank(message: 'Local user id must not be blank.')]
    private string $id;

    private ?string $keycloakId;

    private DateTimeImmutable $createdAt;

    #[Override]
    public function getId(): string
    {
        return $this->id;
    }

    #[Override]
    public function getKeycloakId(): ?string
    {
        return $this->keycloakId;
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
     * @return list<string>
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
