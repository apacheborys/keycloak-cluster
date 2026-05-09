<?php

declare(strict_types=1);

namespace App\Keycloak;

use Apacheborys\KeycloakPhpClient\DTO\Request\Oidc\OidcTokenRequestDto;
use Apacheborys\KeycloakPhpClient\DTO\Request\User\SearchUsersDto;
use Apacheborys\KeycloakPhpClient\Entity\JsonWebToken;
use Apacheborys\KeycloakPhpClient\Entity\JwtPayload;
use Apacheborys\KeycloakPhpClient\Entity\KeycloakUser;
use Apacheborys\KeycloakPhpClient\Service\KeycloakServiceInterface;
use Apacheborys\KeycloakPhpClient\ValueObject\OidcGrantType;
use Apacheborys\SymfonyKeycloakBridgeBundle\Model\UserEntityConfig;
use Apacheborys\SymfonyKeycloakBridgeBundle\Security\KeycloakJwtAuthenticator;
use Apacheborys\SymfonyKeycloakBridgeBundle\Security\KeycloakJwtUser;
use Apacheborys\SymfonyKeycloakBridgeBundle\Security\Exception\KeycloakJwtAuthenticationException;
use Apacheborys\SymfonyKeycloakBridgeBundle\Service\Internal\CallsignValuePrefixer;
use LogicException;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Throwable;

final readonly class KeycloakLocalIdFallbackFlowService
{
    /**
     * @param iterable<UserEntityConfig> $userEntityConfigs
     */
    public function __construct(
        private KeycloakServiceInterface $keycloakService,
        private KeycloakJwtAuthenticator $jwtAuthenticator,
        private KeycloakJwtAuthenticatorFactory $jwtAuthenticatorFactory,
        #[AutowireIterator('keycloak.user_entity_config')]
        private iterable $userEntityConfigs,
        private CallsignValuePrefixer $callsignValuePrefixer,
        private ValidatorInterface $validator,
        #[Autowire('%env(KEYCLOAK_BRIDGE_MAPPER_REALM)%')]
        private string $mapperRealm,
        #[Autowire('%env(KEYCLOAK_BRIDGE_MAPPER_CLIENT_ID)%')]
        private string $mapperClientId,
        #[Autowire('%env(KEYCLOAK_BRIDGE_MAPPER_CLIENT_SECRET)%')]
        private string $mapperClientSecret,
    ) {
    }

    public function run(
        LocalIdFallbackFlowInput $input,
        bool $cleanup = true,
        ?callable $reportStep = null,
    ): LocalIdFallbackFlowResult {
        $this->validateInput($input);

        $localUserWithoutKeycloakId = $input->getInitialUser();
        $updatedLocalUserWithoutKeycloakId = $input->getUpdatedUser();
        $passwordDto = $input->getPasswordDto();
        $plainPasswordForLogin = $input->getPlainPasswordForLogin();

        $createdUser = null;
        $userDeleted = false;
        $flowError = null;
        $result = null;

        try {
            $this->report($reportStep, 1, 'Create user without persisted Keycloak id');
            $createdUser = $this->keycloakService->createUser(
                localUser: $localUserWithoutKeycloakId,
                passwordDto: $passwordDto,
            );
            $identifierConfig = $this->resolveIdentifierConfig(FixtureUser::class);
            $this->ensureKeycloakIdentifierAttribute(
                keycloakUser: $createdUser,
                attributeName: $identifierConfig->attributeName,
                expectedLocalUserId: $localUserWithoutKeycloakId->getId(),
            );

            $this->report($reportStep, 2, 'Find the same user via local-id attribute fallback');
            $foundUser = $this->keycloakService->findUser($localUserWithoutKeycloakId);
            $this->ensureSameKeycloakUser(
                expected: $createdUser,
                actual: $foundUser,
                operation: 'findUser',
            );
            $this->ensureKeycloakIdentifierAttribute(
                keycloakUser: $foundUser,
                attributeName: $identifierConfig->attributeName,
                expectedLocalUserId: $localUserWithoutKeycloakId->getId(),
            );

            $this->report($reportStep, 3, 'Update the same user via local-id attribute fallback');
            $updatedUser = $this->keycloakService->updateUser(
                oldUserVersion: $localUserWithoutKeycloakId,
                newUserVersion: $updatedLocalUserWithoutKeycloakId,
            );
            $this->ensureUpdatedUserMatches(
                expected: $updatedLocalUserWithoutKeycloakId,
                updatedUser: $updatedUser,
            );
            $this->ensureKeycloakIdentifierAttribute(
                keycloakUser: $updatedUser,
                attributeName: $identifierConfig->attributeName,
                expectedLocalUserId: $updatedLocalUserWithoutKeycloakId->getId(),
            );

            $this->report($reportStep, 4, 'Login updated user and receive access/refresh tokens');
            $loginResult = $this->keycloakService->loginUser(
                user: $updatedLocalUserWithoutKeycloakId,
                plainPassword: $plainPasswordForLogin,
            );

            $this->report($reportStep, 5, 'Verify callsigned identifier claim and stripped user id from access JWT');
            $accessTokenInspection = $this->inspectJwtIdentifier(
                jwt: $loginResult->getAccessToken()->getRawToken(),
                expectedLocalUserId: $updatedLocalUserWithoutKeycloakId->getId(),
                identifierConfig: $identifierConfig,
            );

            $refreshToken = $loginResult->getRefreshToken();
            if (!is_string($refreshToken) || $refreshToken === '') {
                throw new LogicException('Refresh token is missing after login.');
            }

            $this->report($reportStep, 6, 'Refresh token');
            $refreshResult = $this->keycloakService->refreshToken(
                dto: new OidcTokenRequestDto(
                    realm: $this->mapperRealm,
                    clientId: $this->mapperClientId,
                    clientSecret: $this->mapperClientSecret,
                    refreshToken: $refreshToken,
                    grantType: OidcGrantType::REFRESH_TOKEN,
                ),
            );

            $this->report($reportStep, 7, 'Verify callsigned identifier claim and stripped user id from refreshed JWT');
            $refreshedTokenInspection = $this->inspectJwtIdentifier(
                jwt: $refreshResult->getAccessToken()->getRawToken(),
                expectedLocalUserId: $updatedLocalUserWithoutKeycloakId->getId(),
                identifierConfig: $identifierConfig,
            );
            if ($accessTokenInspection->claimName !== $refreshedTokenInspection->claimName) {
                throw new LogicException(sprintf(
                    'Access and refreshed JWTs expose different identifier claims: "%s" and "%s".',
                    $accessTokenInspection->claimName,
                    $refreshedTokenInspection->claimName,
                ));
            }

            $this->report($reportStep, 8, 'Delete user via local-id attribute fallback');
            $this->keycloakService->deleteUser($updatedLocalUserWithoutKeycloakId);
            $userDeleted = true;

            $this->report($reportStep, 9, 'Verify user is no longer present in Keycloak');
            $deletionVerified = $this->verifyDeletion($updatedLocalUserWithoutKeycloakId);
            if ($deletionVerified !== true) {
                throw new LogicException('User still exists in Keycloak after fallback deletion.');
            }

            $result = new LocalIdFallbackFlowResult(
                createdUser: $createdUser,
                foundUser: $foundUser,
                updatedUser: $updatedUser,
                loginResult: $loginResult,
                refreshResult: $refreshResult,
                identifierAttributeName: $identifierConfig->attributeName,
                identifierClaimName: $accessTokenInspection->claimName,
                accessTokenIdentifierClaimValue: $accessTokenInspection->claimValue,
                refreshedTokenIdentifierClaimValue: $refreshedTokenInspection->claimValue,
                authenticatedUserIdentifier: $accessTokenInspection->resolvedUserIdentifier,
                refreshedAuthenticatedUserIdentifier: $refreshedTokenInspection->resolvedUserIdentifier,
                deletionVerified: $deletionVerified,
            );
        } catch (Throwable $exception) {
            $flowError = $exception;
        }

        if ($cleanup && !$userDeleted && $createdUser instanceof KeycloakUser) {
            try {
                $this->report($reportStep, 8, 'Cleanup: delete user via local-id attribute fallback');
                $this->keycloakService->deleteUser($updatedLocalUserWithoutKeycloakId);
            } catch (Throwable $cleanupException) {
                if ($flowError instanceof Throwable) {
                    throw new RuntimeException(
                        sprintf(
                            'Local-id fallback flow failed and cleanup failed. Flow error: %s. Cleanup error: %s',
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

        if (!$result instanceof LocalIdFallbackFlowResult) {
            throw new RuntimeException('Local-id fallback flow finished without result.');
        }

        return $result;
    }

    private function validateInput(LocalIdFallbackFlowInput $input): void
    {
        $violations = $this->validator->validate($input);
        if ($violations->count() > 0) {
            throw new ValidationFailedException($input, $violations);
        }
    }

    private function ensureSameKeycloakUser(KeycloakUser $expected, KeycloakUser $actual, string $operation): void
    {
        if ($expected->getKeycloakId() !== $actual->getKeycloakId()) {
            throw new LogicException(sprintf(
                'Fallback %s resolved unexpected Keycloak user. Expected "%s", got "%s".',
                $operation,
                $expected->getKeycloakId(),
                $actual->getKeycloakId(),
            ));
        }
    }

    private function ensureUpdatedUserMatches(FixtureUser $expected, KeycloakUser $updatedUser): void
    {
        if ($updatedUser->getUsername() !== $expected->getUsername()) {
            throw new LogicException('Fallback update did not preserve username.');
        }

        if ($updatedUser->getEmail() !== $expected->getEmail()) {
            throw new LogicException(sprintf(
                'Fallback update did not update email. Expected "%s", got "%s".',
                $expected->getEmail(),
                $updatedUser->getEmail(),
            ));
        }

        if ($updatedUser->getFirstName() !== $expected->getFirstName()) {
            throw new LogicException(sprintf(
                'Fallback update did not update first name. Expected "%s", got "%s".',
                $expected->getFirstName(),
                $updatedUser->getFirstName(),
            ));
        }

        if ($updatedUser->getLastName() !== $expected->getLastName()) {
            throw new LogicException(sprintf(
                'Fallback update did not update last name. Expected "%s", got "%s".',
                $expected->getLastName(),
                $updatedUser->getLastName(),
            ));
        }
    }

    private function ensureKeycloakIdentifierAttribute(
        KeycloakUser $keycloakUser,
        string $attributeName,
        string $expectedLocalUserId,
    ): void {
        $expectedAttributeValue = $this->callsignValuePrefixer->prefix($expectedLocalUserId);
        $attributeValues = $keycloakUser->getAttributes()[$attributeName] ?? null;
        if (!is_array($attributeValues) || !in_array($expectedAttributeValue, $attributeValues, true)) {
            throw new LogicException(sprintf(
                'Keycloak user "%s" does not expose the expected callsigned identifier attribute "%s".',
                $keycloakUser->getKeycloakId(),
                $attributeName,
            ));
        }
    }

    private function inspectJwtIdentifier(
        string $jwt,
        string $expectedLocalUserId,
        object $identifierConfig,
    ): object {
        $parsedJwt = JsonWebToken::fromRawToken(rawToken: $jwt);
        $claimInspection = $this->resolveIdentifierClaimValue(
            payload: $parsedJwt->getPayload(),
            identifierConfig: $identifierConfig,
            expectedLocalUserId: $expectedLocalUserId,
        );
        $resolvedUserIdentifier = $this->authenticateJwtAndResolveUserIdentifier(
            jwt: $jwt,
            expectedLocalUserId: $expectedLocalUserId,
        );

        return (object) [
            'claimName' => $claimInspection->claimName,
            'claimValue' => $claimInspection->claimValue,
            'resolvedUserIdentifier' => $resolvedUserIdentifier,
        ];
    }

    private function resolveIdentifierClaimValue(
        JwtPayload $payload,
        object $identifierConfig,
        string $expectedLocalUserId,
    ): object {
        $resolvedClaimName = $this->resolveExistingClaimName(
            payload: $payload,
            claimNames: $identifierConfig->claimNames,
        );

        if ($resolvedClaimName === null) {
            throw new LogicException(sprintf(
                'JWT does not expose any expected identifier claim. Tried: [%s].',
                implode(', ', $identifierConfig->claimNames),
            ));
        }

        $claimValue = $payload->getClaim($resolvedClaimName);
        if (!is_string($claimValue) || trim($claimValue) === '') {
            throw new LogicException(sprintf(
                'JWT identifier claim "%s" is empty or not a string.',
                $resolvedClaimName,
            ));
        }

        $expectedClaimValue = $this->callsignValuePrefixer->prefix($expectedLocalUserId);
        if (trim($claimValue) !== $expectedClaimValue) {
            throw new LogicException(sprintf(
                'JWT identifier claim "%s" contains "%s", expected "%s".',
                $resolvedClaimName,
                $claimValue,
                $expectedClaimValue,
            ));
        }

        return (object) [
            'claimName' => $resolvedClaimName,
            'claimValue' => $expectedClaimValue,
        ];
    }

    private function authenticateJwtAndResolveUserIdentifier(string $jwt, string $expectedLocalUserId): string
    {
        try {
            $passport = $this->authenticateWithAuthenticator(
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
                    $passport = $this->authenticateWithAuthenticator(
                        authenticator: $this->jwtAuthenticatorFactory->createForIssuer($token->getPayload()->getIss()),
                        jwt: $jwt,
                    );
                } catch (AuthenticationException $derivedException) {
                    throw new LogicException('KeycloakJwtAuthenticator rejected JWT token: ' . $derivedException->getMessage(), 0, $derivedException);
                }
            } else {
                throw new LogicException('KeycloakJwtAuthenticator rejected JWT token: ' . $exception->getMessage(), 0, $exception);
            }
        }

        $userBadge = $passport->getBadge(UserBadge::class);
        if (!$userBadge instanceof UserBadge) {
            throw new LogicException('KeycloakJwtAuthenticator did not attach a UserBadge to the passport.');
        }

        $resolvedUserIdentifier = $userBadge->getUserIdentifier();
        if ($resolvedUserIdentifier !== $expectedLocalUserId) {
            throw new LogicException(sprintf(
                'KeycloakJwtAuthenticator resolved "%s" instead of the expected local id "%s".',
                $resolvedUserIdentifier,
                $expectedLocalUserId,
            ));
        }

        $authenticatedUser = $passport->getUser();
        if (!$authenticatedUser instanceof KeycloakJwtUser) {
            throw new LogicException(sprintf(
                'KeycloakJwtAuthenticator returned unexpected user class "%s".',
                $authenticatedUser::class,
            ));
        }

        if ($authenticatedUser->getUserIdentifier() !== $expectedLocalUserId) {
            throw new LogicException(sprintf(
                'Authenticated JWT user exposes "%s" instead of the expected local id "%s".',
                $authenticatedUser->getUserIdentifier(),
                $expectedLocalUserId,
            ));
        }

        return $resolvedUserIdentifier;
    }

    private function authenticateWithAuthenticator(KeycloakJwtAuthenticator $authenticator, string $jwt): Passport
    {
        $request = new Request(server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwt]);

        if ($authenticator->supports($request) !== true) {
            throw new LogicException('KeycloakJwtAuthenticator does not support the provided JWT token.');
        }

        return $authenticator->authenticate($request);
    }

    private function verifyDeletion(FixtureUser $user): bool
    {
        $matchingUsers = $this->keycloakService->searchUsers(
            dto: new SearchUsersDto(
                realm: $this->mapperRealm,
                email: $user->getEmail(),
                exact: true,
                max: 5,
            ),
        );

        return $matchingUsers === [];
    }

    /**
     * @param list<string> $claimNames
     */
    private function resolveExistingClaimName(JwtPayload $payload, array $claimNames): ?string
    {
        foreach ($claimNames as $claimName) {
            if ($payload->hasClaim($claimName)) {
                return $claimName;
            }
        }

        return null;
    }

    /**
     * @return object{attributeName: string, claimNames: list<string>}
     */
    private function resolveIdentifierConfig(string $userClass): object
    {
        foreach ($this->userEntityConfigs as $userEntityConfig) {
            if (!$userEntityConfig instanceof UserEntityConfig) {
                continue;
            }

            if ($userEntityConfig->getClassName() !== $userClass) {
                continue;
            }

            $claimNames = $userEntityConfig->getUserIdentifierJwtClaimNames();
            if ($claimNames === []) {
                throw new LogicException(sprintf(
                    'User entity config "%s" does not expose any identifier claim names.',
                    $userClass,
                ));
            }

            return (object) [
                'attributeName' => $userEntityConfig->getUserIdentifierAttributeConfig()->getAttributeName(),
                'claimNames' => $claimNames,
            ];
        }

        throw new LogicException(sprintf(
            'User entity config for "%s" was not found in the container.',
            $userClass,
        ));
    }

    private function report(?callable $reportStep, int $stepNumber, string $message): void
    {
        if ($reportStep !== null) {
            $reportStep($stepNumber, $message);
        }
    }
}
