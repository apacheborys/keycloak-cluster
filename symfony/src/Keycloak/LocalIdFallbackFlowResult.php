<?php

declare(strict_types=1);

namespace App\Keycloak;

use Apacheborys\KeycloakPhpClient\DTO\Response\Oidc\OidcTokenResponseDto;
use Apacheborys\KeycloakPhpClient\Entity\KeycloakUser;

final readonly class LocalIdFallbackFlowResult
{
    public function __construct(
        private KeycloakUser $createdUser,
        private KeycloakUser $foundUser,
        private KeycloakUser $updatedUser,
        private OidcTokenResponseDto $loginResult,
        private OidcTokenResponseDto $refreshResult,
        private string $identifierAttributeName,
        private string $identifierClaimName,
        private string $accessTokenIdentifierClaimValue,
        private string $refreshedTokenIdentifierClaimValue,
        private string $authenticatedUserIdentifier,
        private string $refreshedAuthenticatedUserIdentifier,
        private bool $deletionVerified,
    ) {
    }

    public function getCreatedUser(): KeycloakUser
    {
        return $this->createdUser;
    }

    public function getFoundUser(): KeycloakUser
    {
        return $this->foundUser;
    }

    public function getUpdatedUser(): KeycloakUser
    {
        return $this->updatedUser;
    }

    public function getLoginResult(): OidcTokenResponseDto
    {
        return $this->loginResult;
    }

    public function getRefreshResult(): OidcTokenResponseDto
    {
        return $this->refreshResult;
    }

    public function getIdentifierAttributeName(): string
    {
        return $this->identifierAttributeName;
    }

    public function getIdentifierClaimName(): string
    {
        return $this->identifierClaimName;
    }

    public function getAccessTokenIdentifierClaimValue(): string
    {
        return $this->accessTokenIdentifierClaimValue;
    }

    public function getRefreshedTokenIdentifierClaimValue(): string
    {
        return $this->refreshedTokenIdentifierClaimValue;
    }

    public function getAuthenticatedUserIdentifier(): string
    {
        return $this->authenticatedUserIdentifier;
    }

    public function getRefreshedAuthenticatedUserIdentifier(): string
    {
        return $this->refreshedAuthenticatedUserIdentifier;
    }

    public function isDeletionVerified(): bool
    {
        return $this->deletionVerified;
    }
}
