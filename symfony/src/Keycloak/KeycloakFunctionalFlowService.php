<?php

declare(strict_types=1);

namespace App\Keycloak;

use Apacheborys\KeycloakPhpClient\DTO\Request\Oidc\OidcTokenRequestDto;
use Apacheborys\KeycloakPhpClient\Service\KeycloakServiceInterface;
use Apacheborys\KeycloakPhpClient\ValueObject\OidcGrantType;
use Apacheborys\KeycloakPhpClient\Entity\KeycloakUserInterface;
use LogicException;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Throwable;

final readonly class KeycloakFunctionalFlowService
{
    public function __construct(
        private KeycloakServiceInterface $keycloakService,
        private KeycloakUserCloneFactory $userCloneFactory,
        private ValidatorInterface $validator,
        #[Autowire('%env(KEYCLOAK_BRIDGE_CLIENT_REALM)%')]
        private string $clientRealm,
        #[Autowire('%env(KEYCLOAK_BRIDGE_CLIENT_ID)%')]
        private string $clientId,
        #[Autowire('%env(KEYCLOAK_BRIDGE_CLIENT_SECRET)%')]
        private string $clientSecret,
    ) {
    }

    public function runCreateLoginRefreshDelete(
        FunctionalFlowInput $input,
        ?callable $reportStep = null,
    ): KeycloakFunctionalFlowResult {
        $this->validateInput($input);

        $localUser = $input->getLocalUser();
        $passwordDto = $input->getPasswordDto();
        $plainPasswordForLogin = $input->getPlainPasswordForLogin();
        $cleanupUser = null;

        try {
            $this->report($reportStep, 1, 'Create user');
            $createdUser = $this->keycloakService->createUser(
                localUser: $localUser,
                passwordDto: $passwordDto,
            );

            $cleanupUser = $this->buildCleanupUser(
                localUser: $localUser,
                keycloakUserId: $createdUser->getKeycloakId(),
            );

            $this->report($reportStep, 2, sprintf('Verify created user (keycloak_id=%s)', $createdUser->getKeycloakId()));
            $this->verifyCreatedUser(
                localUser: $localUser,
                keycloakUsername: $createdUser->getUsername(),
                keycloakEmail: $createdUser->getEmail(),
            );

            $this->report($reportStep, 3, 'Login with provided plain password');
            $loginResult = $this->keycloakService->loginUser(
                user: $localUser,
                plainPassword: $plainPasswordForLogin,
            );

            $refreshToken = $loginResult->getRefreshToken();
            if ($refreshToken === null || $refreshToken === '') {
                throw new LogicException('Login succeeded, but refresh token is missing.');
            }

            $this->report($reportStep, 4, 'Refresh token');
            $refreshResult = $this->keycloakService->refreshToken(
                dto: new OidcTokenRequestDto(
                    realm: $this->clientRealm,
                    clientId: $this->clientId,
                    clientSecret: $this->clientSecret,
                    refreshToken: $refreshToken,
                    grantType: OidcGrantType::REFRESH_TOKEN,
                ),
            );

            $this->report($reportStep, 5, 'Delete user');
            $this->keycloakService->deleteUser($cleanupUser);

            return new KeycloakFunctionalFlowResult(
                createdUser: $createdUser,
                loginResult: $loginResult,
                refreshResult: $refreshResult,
            );
        } catch (Throwable $e) {
            if ($cleanupUser instanceof KeycloakUserInterface) {
                try {
                    $this->report($reportStep, 5, 'Cleanup: delete user after failure');
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

    private function validateInput(FunctionalFlowInput $input): void
    {
        $violations = $this->validator->validate($input);
        if ($violations->count() > 0) {
            throw new ValidationFailedException($input, $violations);
        }
    }

    private function report(?callable $reportStep, int $stepNumber, string $message): void
    {
        if ($reportStep !== null) {
            $reportStep($stepNumber, $message);
        }
    }

    private function verifyCreatedUser(LocalUser $localUser, string $keycloakUsername, string $keycloakEmail): void
    {
        if ($keycloakUsername !== $localUser->getUsername() || $keycloakEmail !== $localUser->getEmail()) {
            throw new LogicException('Created user verification failed: mismatch in username or email.');
        }
    }

    private function buildCleanupUser(LocalUser $localUser, string $keycloakUserId): KeycloakUserInterface
    {
        return $this->userCloneFactory->withKeycloakId(
            localUser: $localUser,
            keycloakId: $keycloakUserId,
        );
    }
}
