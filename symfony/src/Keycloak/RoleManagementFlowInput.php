<?php

declare(strict_types=1);

namespace App\Keycloak;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class RoleManagementFlowInput
{
    /**
     * @param list<string> $updatedRoles
     */
    public function __construct(
        #[Assert\Valid]
        private LocalUser $localUser,
        #[Assert\NotBlank(message: 'Plain password must not be blank.')]
        private string $plainPassword,
        #[Assert\All([
            new Assert\NotBlank(message: 'Updated role names must not be blank.'),
        ])]
        private array $updatedRoles,
    ) {
    }

    public function getLocalUser(): LocalUser
    {
        return $this->localUser;
    }

    public function getPlainPassword(): string
    {
        return $this->plainPassword;
    }

    /**
     * @return list<string>
     */
    public function getUpdatedRoles(): array
    {
        return $this->updatedRoles;
    }
}
