<?php

declare(strict_types=1);

namespace App\Keycloak;

use Apacheborys\KeycloakPhpClient\DTO\Response\Oidc\OidcTokenResponseDto;
use Apacheborys\KeycloakPhpClient\Entity\KeycloakUser;

final readonly class JwtAuthorizationFlowResult
{
    /**
     * @param non-empty-string $protectedEndpointUserIdentifier
     * @param non-empty-string $protectedEndpointExpectedRole
     */
    public function __construct(
        private KeycloakUser $createdUser,
        private OidcTokenResponseDto $loginResult,
        private OidcTokenResponseDto $refreshResult,
        private bool $accessTokenValid,
        private bool $refreshedAccessTokenValid,
        private bool $debugVerifyEndpointValid,
        private bool $protectedEndpointValid,
        private bool $protectedEndpointNegativeChecksValid,
        private string $protectedEndpointUserIdentifier,
        private string $protectedEndpointExpectedRole,
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

    public function isDebugVerifyEndpointValid(): bool
    {
        return $this->debugVerifyEndpointValid;
    }

    public function isProtectedEndpointValid(): bool
    {
        return $this->protectedEndpointValid;
    }

    public function isProtectedEndpointNegativeChecksValid(): bool
    {
        return $this->protectedEndpointNegativeChecksValid;
    }

    public function getProtectedEndpointUserIdentifier(): string
    {
        return $this->protectedEndpointUserIdentifier;
    }

    public function getProtectedEndpointExpectedRole(): string
    {
        return $this->protectedEndpointExpectedRole;
    }
}
