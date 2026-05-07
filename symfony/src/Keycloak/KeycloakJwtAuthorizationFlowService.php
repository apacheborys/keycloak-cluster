<?php

declare(strict_types=1);

namespace App\Keycloak;

use Apacheborys\KeycloakPhpClient\DTO\PasswordDto;
use Apacheborys\KeycloakPhpClient\DTO\Request\Oidc\OidcTokenRequestDto;
use Apacheborys\KeycloakPhpClient\Entity\JsonWebToken;
use Apacheborys\KeycloakPhpClient\Entity\KeycloakUser;
use Apacheborys\KeycloakPhpClient\Entity\KeycloakUserInterface;
use Apacheborys\KeycloakPhpClient\Service\KeycloakJwtVerificationServiceInterface;
use Apacheborys\KeycloakPhpClient\Service\KeycloakServiceInterface;
use Apacheborys\KeycloakPhpClient\ValueObject\KeycloakClientConfig;
use Apacheborys\KeycloakPhpClient\ValueObject\OidcGrantType;
use Apacheborys\SymfonyKeycloakBridgeBundle\Security\KeycloakJwtAuthenticator;
use Apacheborys\SymfonyKeycloakBridgeBundle\Service\Internal\CallsignValuePrefixer;
use LogicException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Throwable;

final readonly class KeycloakJwtAuthorizationFlowService
{
    public function __construct(
        private KeycloakServiceInterface $keycloakService,
        private KeycloakJwtAuthenticator $jwtAuthenticator,
        private KeycloakJwtVerificationServiceInterface $jwtVerificationService,
        private KeycloakClientConfig $keycloakClientConfig,
        #[AutowireIterator('keycloak.user_entity_config')]
        private iterable $userEntityConfigs,
        private CallsignValuePrefixer $callsignValuePrefixer,
    ) {
    }

    public function runCreateLoginVerifyRefreshDelete(
        KeycloakUserInterface $localUser,
        PasswordDto $passwordDto,
        string $plainPasswordForLogin,
        string $refreshRealm,
        string $refreshClientId,
        string $refreshClientSecret,
        bool $cleanup = true,
        ?callable $reportStep = null,
    ): JwtAuthorizationFlowResult {
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
                        $this->cloneUserWithKeycloakId($localUser, $createdUser->getKeycloakId()),
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

    private function authenticateJwt(string $jwt): bool
    {
        $authenticator = $this->jwtAuthenticator;
        $request = new Request(server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwt]);

        if ($authenticator->supports($request) !== true) {
            $token = JsonWebToken::fromRawToken(rawToken: $jwt);
            $authenticator = $this->buildAuthenticatorForIssuer($token->getPayload()->getIss());
            $request = new Request(server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwt]);

            if ($authenticator->supports($request) !== true) {
                throw new LogicException('KeycloakJwtAuthenticator does not support the provided JWT token.');
            }
        }

        try {
            $authenticator->authenticate($request);
        } catch (AuthenticationException $exception) {
            throw new LogicException('KeycloakJwtAuthenticator rejected JWT token: ' . $exception->getMessage(), 0, $exception);
        }

        return true;
    }

    private function buildAuthenticatorForIssuer(string $issuer): KeycloakJwtAuthenticator
    {
        $derivedBaseUrl = $this->extractBaseUrlFromIssuer($issuer);
        $derivedConfig = new KeycloakClientConfig(
            baseUrl: $derivedBaseUrl,
            clientRealm: $this->keycloakClientConfig->getClientRealm(),
            clientId: $this->keycloakClientConfig->getClientId(),
            clientSecret: $this->keycloakClientConfig->getClientSecret(),
            realmListTtl: $this->keycloakClientConfig->getRealmListTtl(),
        );

        return new KeycloakJwtAuthenticator(
            jwtVerificationService: $this->jwtVerificationService,
            keycloakClientConfig: $derivedConfig,
            userEntityConfigs: $this->userEntityConfigs,
            callsignValuePrefixer: $this->callsignValuePrefixer,
        );
    }

    private function extractBaseUrlFromIssuer(string $issuer): string
    {
        $parts = parse_url($issuer);
        if (!is_array($parts)) {
            throw new LogicException(sprintf('Unable to parse issuer URL "%s".', $issuer));
        }

        $scheme = (string) ($parts['scheme'] ?? '');
        $host = (string) ($parts['host'] ?? '');
        if ($scheme === '' || $host === '') {
            throw new LogicException(sprintf('Issuer URL "%s" does not contain scheme and host.', $issuer));
        }

        $port = $parts['port'] ?? null;
        $path = (string) ($parts['path'] ?? '');
        $realmPosition = strpos($path, '/realms/');
        if ($realmPosition !== false) {
            $path = substr($path, 0, $realmPosition);
        } else {
            $path = '';
        }

        $baseUrl = $scheme . '://' . $host;
        if (is_int($port)) {
            $baseUrl .= ':' . $port;
        }

        $normalizedPath = trim($path, '/');
        if ($normalizedPath !== '') {
            $baseUrl .= '/' . $normalizedPath;
        }

        return $baseUrl;
    }

    private function cloneUserWithKeycloakId(KeycloakUserInterface $localUser, string $keycloakId): KeycloakUserInterface
    {
        if ($localUser instanceof LocalUser) {
            return new LocalUser(
                username: $localUser->getUsername(),
                email: $localUser->getEmail(),
                firstName: $localUser->getFirstName(),
                lastName: $localUser->getLastName(),
                enabled: $localUser->isEnabled(),
                emailVerified: $localUser->isEmailVerified(),
                roles: $localUser->getRoles(),
                id: $localUser->getId(),
                keycloakId: $keycloakId,
            );
        }

        if ($localUser instanceof FixtureUser) {
            return new FixtureUser(
                username: $localUser->getUsername(),
                email: $localUser->getEmail(),
                firstName: $localUser->getFirstName(),
                lastName: $localUser->getLastName(),
                enabled: $localUser->isEnabled(),
                emailVerified: $localUser->isEmailVerified(),
                roles: $localUser->getRoles(),
                id: $localUser->getId(),
                keycloakId: $keycloakId,
            );
        }

        throw new LogicException(sprintf(
            'Unsupported local user class "%s" for cleanup cloning.',
            $localUser::class,
        ));
    }

    private function report(?callable $reportStep, int $stepNumber, string $message): void
    {
        if ($reportStep !== null) {
            $reportStep($stepNumber, $message);
        }
    }
}
