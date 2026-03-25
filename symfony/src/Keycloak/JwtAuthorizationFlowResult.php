<?php

declare(strict_types=1);

namespace App\Keycloak;

use Apacheborys\KeycloakPhpClient\DTO\Response\OidcTokenResponseDto;
use Apacheborys\KeycloakPhpClient\Entity\KeycloakUser;

final readonly class JwtAuthorizationFlowResult
{
    public function __construct(
        private KeycloakUser $createdUser,
        private OidcTokenResponseDto $loginResult,
        private OidcTokenResponseDto $refreshResult,
        private bool $accessTokenValid,
        private bool $refreshedAccessTokenValid,
    ) {
    }

    public function getCreatedUser(): KeycloakUser
    {
        return $this->createdUser;
    }

    public function getLoginResult(): OidcTokenResponseDto
    {
        return $this->loginResult;
    }

    public function getRefreshResult(): OidcTokenResponseDto
    {
        return $this->refreshResult;
    }

    public function isAccessTokenValid(): bool
    {
        return $this->accessTokenValid;
    }

    public function isRefreshedAccessTokenValid(): bool
    {
        return $this->refreshedAccessTokenValid;
    }
}
