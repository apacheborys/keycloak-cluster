<?php

declare(strict_types=1);

namespace App\Keycloak;

use Apacheborys\KeycloakPhpClient\DTO\PasswordDto;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class FunctionalFlowInput
{
    public function __construct(
        #[Assert\Valid]
        private LocalUser $localUser,
        private PasswordDto $passwordDto,
        #[Assert\NotBlank(message: 'Plain password for login must not be blank.')]
        private string $plainPasswordForLogin,
    ) {
    }

    public function getLocalUser(): LocalUser
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
}
