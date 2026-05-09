<?php

declare(strict_types=1);

namespace App\Keycloak;

use Apacheborys\KeycloakPhpClient\DTO\PasswordDto;
use Apacheborys\KeycloakPhpClient\Entity\KeycloakUserInterface;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class JwtAuthorizationFlowInput
{
    public function __construct(
        #[Assert\Valid]
        private KeycloakUserInterface $localUser,
        private PasswordDto $passwordDto,
        #[Assert\NotBlank(message: 'Plain password for login must not be blank.')]
        private string $plainPasswordForLogin,
        #[Assert\NotBlank(message: 'Refresh realm must not be blank.')]
        private string $refreshRealm,
        #[Assert\NotBlank(message: 'Refresh client id must not be blank.')]
        private string $refreshClientId,
        #[Assert\NotBlank(message: 'Refresh client secret must not be blank.')]
        private string $refreshClientSecret,
    ) {
    }

    public function getLocalUser(): KeycloakUserInterface
    {
        return $this->localUser;
    }

    public function getPasswordDto(): PasswordDto
    {
        return $this->passwordDto;
    }

    public function getPlainPasswordForLogin(): string
    {
        return $this->plainPasswordForLogin;
    }

    public function getRefreshRealm(): string
    {
        return $this->refreshRealm;
    }

    public function getRefreshClientId(): string
    {
        return $this->refreshClientId;
    }

    public function getRefreshClientSecret(): string
    {
        return $this->refreshClientSecret;
    }
}
