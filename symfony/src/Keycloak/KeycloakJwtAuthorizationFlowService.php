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
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Throwable;

final readonly class KeycloakJwtAuthorizationFlowService
{
    private const string DEBUG_VERIFY_ENDPOINT = 'http://localhost:8000/api/keycloak/verify';
    private const string PROTECTED_ME_ENDPOINT = 'http://localhost:8000/api/protected/me';

    public function __construct(
        private KeycloakServiceInterface $keycloakService,
        private KeycloakJwtAuthenticator $jwtAuthenticator,
        private KeycloakJwtAuthenticatorFactory $jwtAuthenticatorFactory,
        private KeycloakUserCloneFactory $userCloneFactory,
        private HttpClientInterface $httpClient,
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

            $this->report($reportStep, 3, 'Verify access JWT via direct debug endpoint');
            $debugVerifyEndpointValid = $this->verifyAccessTokenViaDebugEndpoint(
                jwt: $loginResult->getAccessToken()->getRawToken(),
            );

            $expectedProtectedRole = $this->resolveExpectedProtectedRole(jwt: $loginResult->getAccessToken());

            $this->report($reportStep, 4, 'Verify access JWT via protected Symfony Security endpoint');
            $protectedEndpointVerification = $this->verifyAccessTokenViaProtectedEndpoint(
                jwt: $loginResult->getAccessToken()->getRawToken(),
                expectedUserIdentifier: $localUser->getId(),
                expectedRole: $expectedProtectedRole,
            );

            $this->report($reportStep, 5, 'Verify protected endpoint negative authenticator responses');
            $protectedEndpointNegativeChecksValid = $this->verifyProtectedEndpointNegativeScenarios();

            $this->report($reportStep, 6, 'Verify access JWT via KeycloakJwtAuthenticator');
            $accessTokenAuthenticated = $this->authenticateJwt(
                jwt: $loginResult->getAccessToken()->getRawToken(),
            );

            $refreshToken = $loginResult->getRefreshToken();
            if (!is_string($refreshToken) || $refreshToken === '') {
                throw new LogicException('Refresh token is missing after login.');
            }

            $this->report($reportStep, 7, 'Refresh token');
            $refreshResult = $this->keycloakService->refreshToken(
                dto: new OidcTokenRequestDto(
                    realm: $refreshRealm,
                    clientId: $refreshClientId,
                    clientSecret: $refreshClientSecret,
                    refreshToken: $refreshToken,
                    grantType: OidcGrantType::REFRESH_TOKEN,
                ),
            );

            $this->report($reportStep, 8, 'Verify refreshed access JWT via KeycloakJwtAuthenticator');
            $refreshedAccessTokenAuthenticated = $this->authenticateJwt(
                jwt: $refreshResult->getAccessToken()->getRawToken(),
            );

            $result = new JwtAuthorizationFlowResult(
                createdUser: $createdUser,
                loginResult: $loginResult,
                refreshResult: $refreshResult,
                accessTokenValid: $accessTokenAuthenticated,
                refreshedAccessTokenValid: $refreshedAccessTokenAuthenticated,
                debugVerifyEndpointValid: $debugVerifyEndpointValid,
                protectedEndpointValid: true,
                protectedEndpointNegativeChecksValid: $protectedEndpointNegativeChecksValid,
                protectedEndpointUserIdentifier: $protectedEndpointVerification['user_identifier'],
                protectedEndpointExpectedRole: $expectedProtectedRole,
            );
        } catch (Throwable $exception) {
            $flowError = $exception;
        }

        if ($cleanup) {
            try {
                if ($createdUser instanceof KeycloakUser) {
                    $this->report($reportStep, 9, 'Cleanup: delete user');
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

    private function verifyAccessTokenViaDebugEndpoint(string $jwt): bool
    {
        $response = $this->requestJson(
            method: 'POST',
            url: self::DEBUG_VERIFY_ENDPOINT,
            options: [
                'json' => [
                    'token' => $jwt,
                ],
            ],
        );

        if ($response['status'] !== Response::HTTP_OK) {
            throw new LogicException(sprintf(
                'Direct verify endpoint returned HTTP %d, expected 200.',
                $response['status'],
            ));
        }

        if (($response['payload']['valid'] ?? null) !== true) {
            throw new LogicException('Direct verify endpoint did not confirm a valid JWT.');
        }

        return true;
    }

    /**
     * @return array{user_identifier: string}
     */
    private function verifyAccessTokenViaProtectedEndpoint(
        string $jwt,
        string $expectedUserIdentifier,
        string $expectedRole,
    ): array {
        $response = $this->requestJson(
            method: 'GET',
            url: self::PROTECTED_ME_ENDPOINT,
            options: [
                'headers' => [
                    'Authorization' => 'Bearer ' . $jwt,
                ],
            ],
        );

        if ($response['status'] !== Response::HTTP_OK) {
            throw new LogicException(sprintf(
                'Protected endpoint returned HTTP %d, expected 200.',
                $response['status'],
            ));
        }

        $userIdentifier = $response['payload']['user_identifier'] ?? null;
        if (!is_string($userIdentifier) || trim($userIdentifier) === '') {
            throw new LogicException('Protected endpoint did not expose a non-empty user_identifier.');
        }

        if ($userIdentifier !== $expectedUserIdentifier) {
            throw new LogicException(sprintf(
                'Protected endpoint returned user_identifier "%s", expected "%s".',
                $userIdentifier,
                $expectedUserIdentifier,
            ));
        }

        $roles = $this->normalizeResponseRoles($response['payload']['roles'] ?? null);
        if (!in_array($expectedRole, $roles, true)) {
            throw new LogicException(sprintf(
                'Protected endpoint roles [%s] do not contain the expected role "%s".',
                implode(', ', $roles),
                $expectedRole,
            ));
        }

        return [
            'user_identifier' => $userIdentifier,
        ];
    }

    private function verifyProtectedEndpointNegativeScenarios(): bool
    {
        $missingTokenResponse = $this->requestJson(
            method: 'GET',
            url: self::PROTECTED_ME_ENDPOINT,
        );

        if ($missingTokenResponse['status'] !== Response::HTTP_UNAUTHORIZED) {
            throw new LogicException(sprintf(
                'Protected endpoint without Authorization returned HTTP %d, expected 401.',
                $missingTokenResponse['status'],
            ));
        }

        if (($missingTokenResponse['payload']['message'] ?? null) !== 'Authentication required.') {
            throw new LogicException('Protected endpoint without Authorization returned an unexpected message.');
        }

        $malformedTokenResponse = $this->requestJson(
            method: 'GET',
            url: self::PROTECTED_ME_ENDPOINT,
            options: [
                'headers' => [
                    'Authorization' => 'Bearer not-a-jwt',
                ],
            ],
        );

        if ($malformedTokenResponse['status'] !== Response::HTTP_UNAUTHORIZED) {
            throw new LogicException(sprintf(
                'Protected endpoint with malformed token returned HTTP %d, expected 401.',
                $malformedTokenResponse['status'],
            ));
        }

        if (($malformedTokenResponse['payload']['reason'] ?? null) !== KeycloakJwtAuthenticationException::REASON_MALFORMED_TOKEN) {
            throw new LogicException(sprintf(
                'Protected endpoint with malformed token returned reason "%s", expected "%s".',
                is_scalar($malformedTokenResponse['payload']['reason'] ?? null)
                    ? (string) $malformedTokenResponse['payload']['reason']
                    : 'missing',
                KeycloakJwtAuthenticationException::REASON_MALFORMED_TOKEN,
            ));
        }

        return true;
    }

    private function resolveExpectedProtectedRole(JsonWebToken $jwt): string
    {
        $roles = $this->extractRoles(jwt: $jwt);

        return $roles[0] ?? 'ROLE_USER';
    }

    /**
     * @return list<string>
     */
    private function extractRoles(JsonWebToken $jwt): array
    {
        $rawRoles = $jwt->getPayload()->getRealmAccess()['roles'];

        foreach ($jwt->getPayload()->getResourceAccess() as $resourceAccess) {
            foreach ($resourceAccess['roles'] as $resourceRole) {
                if (!is_string($resourceRole)) {
                    continue;
                }

                $rawRoles[] = $resourceRole;
            }
        }

        return $this->normalizeResponseRoles($rawRoles);
    }

    /**
     * @return list<string>
     */
    private function normalizeResponseRoles(mixed $roles): array
    {
        if (!is_array($roles)) {
            return [];
        }

        $normalizedRoles = [];
        foreach ($roles as $role) {
            if (!is_string($role)) {
                continue;
            }

            $trimmedRole = trim($role);
            if ($trimmedRole === '') {
                continue;
            }

            $normalizedRoles[$trimmedRole] = true;
        }

        if ($normalizedRoles === []) {
            $normalizedRoles['ROLE_USER'] = true;
        }

        return array_keys($normalizedRoles);
    }

    /**
     * @param array<string, mixed> $options
     * @return array{status: int, payload: array<string, mixed>}
     */
    private function requestJson(string $method, string $url, array $options = []): array
    {
        $requestOptions = $options;
        $requestOptions['headers'] = array_merge(
            ['Accept' => 'application/json'],
            is_array($options['headers'] ?? null) ? $options['headers'] : [],
        );

        try {
            $response = $this->httpClient->request($method, $url, $requestOptions);
            $statusCode = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (Throwable $exception) {
            throw new LogicException(sprintf(
                'HTTP request to "%s %s" failed: %s',
                $method,
                $url,
                $exception->getMessage(),
            ), 0, $exception);
        }

        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new LogicException(sprintf(
                'HTTP response from "%s %s" is not valid JSON: %s',
                $method,
                $url,
                $exception->getMessage(),
            ), 0, $exception);
        }

        if (!is_array($payload)) {
            throw new LogicException(sprintf(
                'HTTP response from "%s %s" is not a JSON object.',
                $method,
                $url,
            ));
        }

        return [
            'status' => $statusCode,
            'payload' => $payload,
        ];
    }

    private function report(?callable $reportStep, int $stepNumber, string $message): void
    {
        if ($reportStep !== null) {
            $reportStep($stepNumber, $message);
        }
    }
}
