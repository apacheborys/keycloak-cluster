<?php

declare(strict_types=1);

namespace App\Keycloak;

use Apacheborys\KeycloakPhpClient\DTO\PasswordDto;
use Apacheborys\KeycloakPhpClient\DTO\Request\OidcTokenRequestDto;
use Apacheborys\KeycloakPhpClient\Service\KeycloakServiceInterface;
use Apacheborys\KeycloakPhpClient\ValueObject\OidcGrantType;
use LogicException;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

final readonly class KeycloakFunctionalFlowService
{
    public function __construct(
        private KeycloakServiceInterface $keycloakService,
        #[Autowire('%env(KEYCLOAK_BRIDGE_CLIENT_REALM)%')]
        private string $clientRealm,
        #[Autowire('%env(KEYCLOAK_BRIDGE_CLIENT_ID)%')]
        private string $clientId,
        #[Autowire('%env(KEYCLOAK_BRIDGE_CLIENT_SECRET)%')]
        private string $clientSecret,
    ) {
    }

    public function runCreateLoginRefreshDelete(
        LocalUser $localUser,
        PasswordDto $passwordDto,
        string $plainPasswordForLogin,
    ): KeycloakFunctionalFlowResult {
        $cleanupUser = null;

        try {
            $createdUser = $this->keycloakService->createUser(
                localUser: $localUser,
                passwordDto: $passwordDto,
            );

            $cleanupUser = $this->buildCleanupUser(
                localUser: $localUser,
                keycloakUserId: $createdUser->getId(),
            );

            $this->verifyCreatedUser(
                localUser: $localUser,
                keycloakUsername: $createdUser->getUsername(),
                keycloakEmail: $createdUser->getEmail(),
            );

            $loginResult = $this->keycloakService->loginUser(
                user: $localUser,
                plainPassword: $plainPasswordForLogin,
            );

            $refreshToken = $loginResult->getRefreshToken();
            if ($refreshToken === null || $refreshToken === '') {
                throw new LogicException('Login succeeded, but refresh token is missing.');
            }

            $refreshResult = $this->keycloakService->refreshToken(
                dto: new OidcTokenRequestDto(
                    realm: $this->clientRealm,
                    clientId: $this->clientId,
                    clientSecret: $this->clientSecret,
                    refreshToken: $refreshToken,
                    grantType: OidcGrantType::REFRESH_TOKEN,
                ),
            );

            $this->keycloakService->deleteUser($cleanupUser);

            return new KeycloakFunctionalFlowResult(
                createdUser: $createdUser,
                loginResult: $loginResult,
                refreshResult: $refreshResult,
            );
        } catch (Throwable $e) {
            if ($cleanupUser instanceof LocalUser) {
                try {
                    $this->keycloakService->deleteUser($cleanupUser);
                } catch (Throwable $cleanupError) {
                    throw new RuntimeException(
                        sprintf(
                            'Flow failed and cleanup failed. Flow error: %s. Cleanup error: %s',
                            $e->getMessage(),
                            $cleanupError->getMessage(),
                        ),
                        previous: $e,
                    );
                }
            }

            throw $e;
        }
    }

    private function verifyCreatedUser(LocalUser $localUser, string $keycloakUsername, string $keycloakEmail): void
    {
        if ($keycloakUsername !== $localUser->getUsername() || $keycloakEmail !== $localUser->getEmail()) {
            throw new LogicException('Created user verification failed: mismatch in username or email.');
        }
    }

    private function buildCleanupUser(LocalUser $localUser, string $keycloakUserId): LocalUser
    {
        return new LocalUser(
            username: $localUser->getUsername(),
            email: $localUser->getEmail(),
            firstName: $localUser->getFirstName(),
            lastName: $localUser->getLastName(),
            enabled: $localUser->isEnabled(),
            emailVerified: $localUser->isEmailVerified(),
            roles: $localUser->getRoles(),
            id: $keycloakUserId,
        );
    }
}
