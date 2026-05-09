<?php

declare(strict_types=1);

namespace App\Keycloak;

use Apacheborys\KeycloakPhpClient\DTO\PasswordDto;
use App\Validator\NullKeycloakId;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final readonly class LocalIdFallbackFlowInput
{
    public function __construct(
        #[Assert\Valid]
        #[NullKeycloakId(label: 'Initial')]
        private FixtureUser $initialUser,
        #[Assert\Valid]
        #[NullKeycloakId(label: 'Updated')]
        private FixtureUser $updatedUser,
        private PasswordDto $passwordDto,
        #[Assert\NotBlank(message: 'Plain password for login must not be blank.')]
        private string $plainPasswordForLogin,
    ) {
    }

    public function getInitialUser(): FixtureUser
    {
        return $this->initialUser;
    }

    public function getUpdatedUser(): FixtureUser
    {
        return $this->updatedUser;
    }

    public function getPasswordDto(): PasswordDto
    {
        return $this->passwordDto;
    }

    public function getPlainPasswordForLogin(): string
    {
        return $this->plainPasswordForLogin;
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if ($this->initialUser->getId() !== $this->updatedUser->getId()) {
            $context
                ->buildViolation('Fallback flow requires the same stable local id before and after update.')
                ->atPath('updatedUser.id')
                ->addViolation();
        }
    }
}
