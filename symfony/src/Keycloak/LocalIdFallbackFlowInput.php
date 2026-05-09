<?php

declare(strict_types=1);

namespace App\Keycloak;

use Apacheborys\KeycloakPhpClient\DTO\PasswordDto;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final readonly class LocalIdFallbackFlowInput
{
    public function __construct(
        #[Assert\Valid]
        private FixtureUser $initialUser,
        #[Assert\Valid]
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
        $this->validateFallbackUser($this->initialUser, 'initialUser', 'Initial', $context);
        $this->validateFallbackUser($this->updatedUser, 'updatedUser', 'Updated', $context);

        if ($this->initialUser->getId() !== $this->updatedUser->getId()) {
            $context
                ->buildViolation('Fallback flow requires the same stable local id before and after update.')
                ->atPath('updatedUser.id')
                ->addViolation();
        }
    }

    private function validateFallbackUser(
        FixtureUser $user,
        string $propertyPath,
        string $label,
        ExecutionContextInterface $context,
    ): void {
        if ($user->getKeycloakId() !== null) {
            $context
                ->buildViolation(sprintf(
                    '%s fixture user must keep keycloakId=null for fallback testing.',
                    $label,
                ))
                ->atPath($propertyPath . '.keycloakId')
                ->addViolation();
        }
    }
}
