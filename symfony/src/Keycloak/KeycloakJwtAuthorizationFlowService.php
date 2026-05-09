<?php

declare(strict_types=1);

namespace App\Keycloak;

use Apacheborys\KeycloakPhpClient\DTO\Request\Oidc\OidcTokenRequestDto;
use Apacheborys\KeycloakPhpClient\Entity\JsonWebToken;
use Apacheborys\KeycloakPhpClient\Entity\KeycloakUser;
use Apacheborys\KeycloakPhpClient\Service\KeycloakServiceInterface;
use Apacheborys\KeycloakPhpClient\ValueObject\OidcGrantType;
use Apacheborys\SymfonyKeycloakBridgeBundle\Security\KeycloakJwtAuthenticator;
use Apacheborys\SymfonyKeycloakBridgeBundle\Security\Exception\KeycloakJwtAuthenticationException;
use LogicException;
use RuntimeException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Throwable;

final readonly class KeycloakJwtAuthorizationFlowService
{
    public function __construct(
        private KeycloakServiceInterface $keycloakService,
        private KeycloakJwtAuthenticator $jwtAuthenticator,
        private KeycloakJwtAuthenticatorFactory $jwtAuthenticatorFactory,
        private KeycloakUserCloneFactory $userCloneFactory,
        private ValidatorInterface $validator,
    ) {
    }

    public function runCreateLoginVerifyRefreshDelete(
        JwtAuthorizationFlowInput $input,
        bool $cleanup = true,
        ?callable $reportStep = null,
    ): JwtAuthorizationFlowResult {
        $this->validateInput($input);

        $localUser = $input->getLocalUser();
        $passwordDto = $input->getPasswordDto();
        $plainPasswordForLogin = $input->getPlainPasswordForLogin();
        $refreshRealm = $input->getRefreshRealm();
        $refreshClientId = $input->getRefreshClientId();
        $refreshClientSecret = $input->getRefreshClientSecret();
        $createdUser = null;
        $flowError = null;
        $result = null;

        try {
            $this->report($reportStep, 1, 'Create user');
            $createdUser = $this->keycloakService->createUser(
                localUser: $localUser,
                passwordDto: $passwordDto,
            );

            $this->report($reportStep, 2, 'Login user and receive access/refresh tokens');
            $loginResult = $this->keycloakService->loginUser(
                user: $localUser,
                plainPassword: $plainPasswordForLogin,
            );

            $this->report($reportStep, 3, 'Verify access JWT via KeycloakJwtAuthenticator');
            $accessTokenAuthenticated = $this->authenticateJwt(
                jwt: $loginResult->getAccessToken()->getRawToken(),
            );

            $refreshToken = $loginResult->getRefreshToken();
            if (!is_string($refreshToken) || $refreshToken === '') {
                throw new LogicException('Refresh token is missing after login.');
            }

            $this->report($reportStep, 4, 'Refresh token');
            $refreshResult = $this->keycloakService->refreshToken(
                dto: new OidcTokenRequestDto(
                    realm: $refreshRealm,
                    clientId: $refreshClientId,
                    clientSecret: $refreshClientSecret,
                    refreshToken: $refreshToken,
                    grantType: OidcGrantType::REFRESH_TOKEN,
                ),
            );

            $this->report($reportStep, 5, 'Verify refreshed access JWT via KeycloakJwtAuthenticator');
            $refreshedAccessTokenAuthenticated = $this->authenticateJwt(
                jwt: $refreshResult->getAccessToken()->getRawToken(),
            );

            $result = new JwtAuthorizationFlowResult(
                createdUser: $createdUser,
                loginResult: $loginResult,
                refreshResult: $refreshResult,
                accessTokenValid: $accessTokenAuthenticated,
                refreshedAccessTokenValid: $refreshedAccessTokenAuthenticated,
            );
        } catch (Throwable $exception) {
            $flowError = $exception;
        }

        if ($cleanup) {
            try {
                if ($createdUser instanceof KeycloakUser) {
                    $this->report($reportStep, 6, 'Cleanup: delete user');
                    $this->keycloakService->deleteUser(
                        $this->userCloneFactory->withKeycloakId($localUser, $createdUser->getKeycloakId()),
                    );
                }
            } catch (Throwable $cleanupException) {
                if ($flowError instanceof Throwable) {
                    throw new RuntimeException(
                        sprintf(
                            'JWT authorization flow failed and cleanup failed. Flow error: %s. Cleanup error: %s',
                            $flowError->getMessage(),
                            $cleanupException->getMessage(),
                        ),
                        previous: $flowError,
                    );
                }

                throw $cleanupException;
            }
        }

        if ($flowError instanceof Throwable) {
            throw $flowError;
        }

        if (!$result instanceof JwtAuthorizationFlowResult) {
            throw new RuntimeException('JWT authorization flow finished without result.');
        }

        return $result;
    }

    private function validateInput(JwtAuthorizationFlowInput $input): void
    {
        $violations = $this->validator->validate($input);
        if ($violations->count() > 0) {
            throw new ValidationFailedException($input, $violations);
        }
    }

    private function authenticateJwt(string $jwt): bool
    {
        try {
            $this->authenticateWithAuthenticator(
                authenticator: $this->jwtAuthenticator,
                jwt: $jwt,
            );
        } catch (AuthenticationException $exception) {
            if (
                $exception instanceof KeycloakJwtAuthenticationException
                && $exception->getReasonCode() === KeycloakJwtAuthenticationException::REASON_UNSUPPORTED_ISSUER
            ) {
                try {
                    $token = JsonWebToken::fromRawToken(rawToken: $jwt);
                    $this->authenticateWithAuthenticator(
                        authenticator: $this->jwtAuthenticatorFactory->createForIssuer($token->getPayload()->getIss()),
                        jwt: $jwt,
                    );

                    return true;
                } catch (AuthenticationException $derivedException) {
                    throw new LogicException('KeycloakJwtAuthenticator rejected JWT token: ' . $derivedException->getMessage(), 0, $derivedException);
                }
            }

            throw new LogicException('KeycloakJwtAuthenticator rejected JWT token: ' . $exception->getMessage(), 0, $exception);
        }

        return true;
    }

    private function authenticateWithAuthenticator(KeycloakJwtAuthenticator $authenticator, string $jwt): void
    {
        $request = new Request(server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwt]);

        if ($authenticator->supports($request) !== true) {
            throw new LogicException('KeycloakJwtAuthenticator does not support the provided JWT token.');
        }

        $authenticator->authenticate($request);
    }

    private function report(?callable $reportStep, int $stepNumber, string $message): void
    {
        if ($reportStep !== null) {
            $reportStep($stepNumber, $message);
        }
    }
}
