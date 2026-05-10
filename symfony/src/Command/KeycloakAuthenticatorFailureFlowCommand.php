<?php

declare(strict_types=1);

namespace App\Command;

use Apacheborys\KeycloakPhpClient\Exception\KeycloakAuthenticationException;
use Apacheborys\KeycloakPhpClient\Exception\KeycloakAuthorizationException;
use Apacheborys\KeycloakPhpClient\Exception\KeycloakErrorContext;
use Apacheborys\KeycloakPhpClient\Exception\KeycloakException;
use Apacheborys\KeycloakPhpClient\Exception\KeycloakInvalidResponseException;
use Apacheborys\KeycloakPhpClient\Exception\KeycloakRateLimitException;
use Apacheborys\KeycloakPhpClient\Exception\KeycloakServerException;
use Apacheborys\KeycloakPhpClient\Exception\KeycloakTransportException;
use Apacheborys\KeycloakPhpClient\Service\KeycloakJwtVerificationServiceInterface;
use Apacheborys\KeycloakPhpClient\ValueObject\KeycloakClientConfig;
use Apacheborys\SymfonyKeycloakBridgeBundle\Model\UserEntityConfig;
use Apacheborys\SymfonyKeycloakBridgeBundle\Security\Exception\KeycloakJwtAuthenticationException;
use Apacheborys\SymfonyKeycloakBridgeBundle\Security\KeycloakJwtAuthenticator;
use Apacheborys\SymfonyKeycloakBridgeBundle\Service\Internal\CallsignValuePrefixer;
use App\Keycloak\LocalUser;
use Closure;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Throwable;

#[AsCommand(
    name: 'keycloak:authenticator-failure:flow',
    description: 'Validate KeycloakJwtAuthenticator failure response mapping without forcing real Keycloak outages'
)]
final class KeycloakAuthenticatorFailureFlowCommand extends Command
{
    /** @var list<UserEntityConfig> */
    private readonly array $userEntityConfigs;

    /**
     * @param iterable<UserEntityConfig> $userEntityConfigs
     */
    public function __construct(
        private readonly KeycloakClientConfig $keycloakClientConfig,
        #[AutowireIterator('keycloak.user_entity_config')]
        iterable $userEntityConfigs,
        private readonly CallsignValuePrefixer $callsignValuePrefixer,
        #[Autowire('%env(bool:KEYCLOAK_BRIDGE_EXPOSE_INFRASTRUCTURE_FAILURE_STATUS)%')]
        private readonly bool $exposeInfrastructureFailureStatus,
        private readonly LoggerInterface $logger,
    ) {
        $this->userEntityConfigs = array_values(iterator_to_array($userEntityConfigs, false));

        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $runId = Uuid::uuid4()->toString();

        try {
            $flowConfig = $this->resolveFlowUserEntityConfig();
            $validJwt = $this->buildValidJwt($flowConfig);
            $scenarios = $this->buildScenarios($validJwt);

            $rows = [];
            $failed = 0;

            foreach ($scenarios as $scenario) {
                $result = $this->runScenario(
                    token: $scenario['token'],
                    verificationService: $scenario['service'],
                    expectedStatus: $scenario['expected_status'],
                    expectedReason: $scenario['expected_reason'],
                );

                $rows[] = [
                    $scenario['name'],
                    (string) $scenario['expected_status'],
                    (string) $result['actual_status'],
                    $scenario['expected_reason'],
                    $result['actual_reason'],
                    $result['ok'] ? 'OK' : 'FAIL',
                ];

                if (!$result['ok']) {
                    $failed++;

                    $this->logger->error('Keycloak authenticator failure flow scenario failed.', [
                        'run_id' => $runId,
                        'scenario' => $scenario['name'],
                        'expected_status' => $scenario['expected_status'],
                        'actual_status' => $result['actual_status'],
                        'expected_reason' => $scenario['expected_reason'],
                        'actual_reason' => $result['actual_reason'],
                    ]);
                }
            }

            $io->section(sprintf('Keycloak authenticator failure flow (run_id=%s)', $runId));
            $io->writeln(sprintf(
                '  expose_infrastructure_failure_status=%s',
                $this->exposeInfrastructureFailureStatus ? '1' : '0',
            ));
            $io->table(
                ['scenario', 'expected status', 'actual status', 'expected reason', 'actual reason', 'OK/FAIL'],
                $rows,
            );

            if ($failed > 0) {
                $io->error(sprintf(
                    'Authenticator failure flow finished with failures: passed=%d, failed=%d.',
                    count($scenarios) - $failed,
                    $failed,
                ));

                return Command::FAILURE;
            }

            $io->success(sprintf(
                'Authenticator failure flow passed: scenarios=%d.',
                count($scenarios),
            ));

            return Command::SUCCESS;
        } catch (Throwable $exception) {
            $this->logger->error('Keycloak authenticator failure flow crashed.', [
                'run_id' => $runId,
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);

            $io->error($exception->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * @return list<array{
     *     name: string,
     *     token: string,
     *     service: KeycloakJwtVerificationServiceInterface,
     *     expected_status: int,
     *     expected_reason: string
     * }>
     */
    private function buildScenarios(string $validJwt): array
    {
        return [
            [
                'name' => 'malformed token',
                'token' => 'not-a-jwt',
                'service' => $this->createVerificationService(static fn (string $_): bool => false),
                'expected_status' => $this->expectedStatus(Response::HTTP_UNAUTHORIZED),
                'expected_reason' => KeycloakJwtAuthenticationException::REASON_MALFORMED_TOKEN,
            ],
            [
                'name' => 'verifyJwt returns false',
                'token' => $validJwt,
                'service' => $this->createVerificationService(static fn (string $_): bool => false),
                'expected_status' => $this->expectedStatus(Response::HTTP_UNAUTHORIZED),
                'expected_reason' => KeycloakJwtAuthenticationException::REASON_SIGNATURE_VALIDATION_FAILED,
            ],
            [
                'name' => 'KeycloakRateLimitException',
                'token' => $validJwt,
                'service' => $this->createVerificationService(
                    fn (string $_): bool => throw new KeycloakRateLimitException(
                        $this->buildKeycloakErrorContext(
                            statusCode: Response::HTTP_TOO_MANY_REQUESTS,
                            keycloakError: 'temporarily_unavailable',
                            keycloakErrorDescription: 'Rate limit exceeded.',
                            correlationIdSuffix: 'rate-limited',
                        ),
                    ),
                ),
                'expected_status' => $this->expectedStatus(Response::HTTP_TOO_MANY_REQUESTS),
                'expected_reason' => KeycloakJwtAuthenticationException::REASON_KEYCLOAK_RATE_LIMITED,
            ],
            [
                'name' => 'KeycloakInvalidResponseException',
                'token' => $validJwt,
                'service' => $this->createVerificationService(
                    fn (string $_): bool => throw new KeycloakInvalidResponseException(
                        $this->buildKeycloakErrorContext(
                            statusCode: Response::HTTP_BAD_GATEWAY,
                            keycloakError: 'invalid_response',
                            keycloakErrorDescription: 'JWK payload could not be parsed.',
                            correlationIdSuffix: 'invalid-response',
                        ),
                    ),
                ),
                'expected_status' => $this->expectedStatus(Response::HTTP_BAD_GATEWAY),
                'expected_reason' => KeycloakJwtAuthenticationException::REASON_KEYCLOAK_INVALID_RESPONSE,
            ],
            [
                'name' => 'KeycloakServerException',
                'token' => $validJwt,
                'service' => $this->createVerificationService(
                    fn (string $_): bool => throw new KeycloakServerException(
                        $this->buildKeycloakErrorContext(
                            statusCode: Response::HTTP_SERVICE_UNAVAILABLE,
                            keycloakError: 'server_error',
                            keycloakErrorDescription: 'Keycloak is temporarily unavailable.',
                            correlationIdSuffix: 'server-error',
                        ),
                    ),
                ),
                'expected_status' => $this->expectedStatus(Response::HTTP_SERVICE_UNAVAILABLE),
                'expected_reason' => KeycloakJwtAuthenticationException::REASON_KEYCLOAK_UNAVAILABLE,
            ],
            [
                'name' => 'KeycloakTransportException',
                'token' => $validJwt,
                'service' => $this->createVerificationService(
                    fn (string $_): bool => throw new KeycloakTransportException(
                        $this->buildKeycloakErrorContext(
                            statusCode: Response::HTTP_SERVICE_UNAVAILABLE,
                            keycloakError: 'transport_error',
                            keycloakErrorDescription: 'Connection to Keycloak failed.',
                            correlationIdSuffix: 'transport-error',
                        ),
                    ),
                ),
                'expected_status' => $this->expectedStatus(Response::HTTP_SERVICE_UNAVAILABLE),
                'expected_reason' => KeycloakJwtAuthenticationException::REASON_KEYCLOAK_UNAVAILABLE,
            ],
            [
                'name' => 'KeycloakAuthenticationException',
                'token' => $validJwt,
                'service' => $this->createVerificationService(
                    fn (string $_): bool => throw new KeycloakAuthenticationException(
                        $this->buildKeycloakErrorContext(
                            statusCode: Response::HTTP_UNAUTHORIZED,
                            keycloakError: 'invalid_client',
                            keycloakErrorDescription: 'Client authentication failed.',
                            correlationIdSuffix: 'authentication-error',
                        ),
                    ),
                ),
                'expected_status' => $this->expectedStatus(Response::HTTP_UNAUTHORIZED),
                'expected_reason' => KeycloakJwtAuthenticationException::REASON_KEYCLOAK_AUTHENTICATION_FAILED,
            ],
            [
                'name' => 'KeycloakAuthorizationException',
                'token' => $validJwt,
                'service' => $this->createVerificationService(
                    fn (string $_): bool => throw new KeycloakAuthorizationException(
                        $this->buildKeycloakErrorContext(
                            statusCode: Response::HTTP_FORBIDDEN,
                            keycloakError: 'insufficient_scope',
                            keycloakErrorDescription: 'The client is not allowed to read JWKS.',
                            correlationIdSuffix: 'authorization-error',
                        ),
                    ),
                ),
                'expected_status' => $this->expectedStatus(Response::HTTP_FORBIDDEN),
                'expected_reason' => KeycloakJwtAuthenticationException::REASON_KEYCLOAK_AUTHORIZATION_FAILED,
            ],
            [
                'name' => 'generic KeycloakException',
                'token' => $validJwt,
                'service' => $this->createVerificationService(
                    fn (string $_): bool => throw new KeycloakException(
                        $this->buildKeycloakErrorContext(
                            statusCode: Response::HTTP_SERVICE_UNAVAILABLE,
                            keycloakError: 'unexpected_failure',
                            keycloakErrorDescription: 'Unexpected Keycloak failure.',
                            correlationIdSuffix: 'generic-error',
                        ),
                    ),
                ),
                'expected_status' => $this->expectedStatus(Response::HTTP_SERVICE_UNAVAILABLE),
                'expected_reason' => KeycloakJwtAuthenticationException::REASON_KEYCLOAK_UNAVAILABLE,
            ],
        ];
    }

    /**
     * @return array{actual_status: int|string, actual_reason: string, ok: bool}
     */
    private function runScenario(
        string $token,
        KeycloakJwtVerificationServiceInterface $verificationService,
        int $expectedStatus,
        string $expectedReason,
    ): array {
        $request = new Request(server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $authenticator = new KeycloakJwtAuthenticator(
            jwtVerificationService: $verificationService,
            keycloakClientConfig: $this->keycloakClientConfig,
            userEntityConfigs: $this->userEntityConfigs,
            callsignValuePrefixer: $this->callsignValuePrefixer,
            exposeInfrastructureFailureStatus: $this->exposeInfrastructureFailureStatus,
            logger: null,
        );

        try {
            if ($authenticator->supports($request) !== true) {
                return [
                    'actual_status' => 'n/a',
                    'actual_reason' => 'supports_returned_false',
                    'ok' => false,
                ];
            }

            $authenticator->authenticate($request);

            return [
                'actual_status' => 'n/a',
                'actual_reason' => 'authenticate_succeeded_unexpectedly',
                'ok' => false,
            ];
        } catch (AuthenticationException $exception) {
            $response = $authenticator->onAuthenticationFailure($request, $exception);
            if (!$response instanceof JsonResponse) {
                return [
                    'actual_status' => $response?->getStatusCode() ?? 'n/a',
                    'actual_reason' => 'non_json_failure_response',
                    'ok' => false,
                ];
            }

            $payload = json_decode((string) $response->getContent(), true);
            $actualReason = is_array($payload) && isset($payload['reason']) && is_string($payload['reason'])
                ? $payload['reason']
                : 'missing_reason';
            $actualStatus = $response->getStatusCode();

            return [
                'actual_status' => $actualStatus,
                'actual_reason' => $actualReason,
                'ok' => $actualStatus === $expectedStatus && $actualReason === $expectedReason,
            ];
        } catch (Throwable $exception) {
            return [
                'actual_status' => 'n/a',
                'actual_reason' => 'unexpected_exception:' . $exception::class,
                'ok' => false,
            ];
        }
    }

    private function resolveFlowUserEntityConfig(): UserEntityConfig
    {
        foreach ($this->userEntityConfigs as $config) {
            if ($config->getClassName() === LocalUser::class) {
                return $config;
            }
        }

        if ($this->userEntityConfigs === []) {
            throw new \LogicException('No keycloak.user_entity_config services are registered.');
        }

        return $this->userEntityConfigs[0];
    }

    private function buildValidJwt(UserEntityConfig $config): string
    {
        $now = time();
        $identifier = Uuid::uuid4()->toString();
        $prefixedIdentifier = $this->callsignValuePrefixer->prefix($identifier);
        $issuer = rtrim($this->keycloakClientConfig->getBaseUrl(), '/') . '/realms/' . $this->keycloakClientConfig->getClientRealm();

        $payload = [
            'exp' => $now + 3600,
            'iat' => $now,
            'jti' => Uuid::uuid4()->toString(),
            'iss' => $issuer,
            'aud' => [$this->keycloakClientConfig->getClientId()],
            'sub' => Uuid::uuid4()->toString(),
            'typ' => 'Bearer',
            'azp' => $this->keycloakClientConfig->getClientId(),
            'acr' => 1,
            'realm_access' => [
                'roles' => ['default-roles-master', 'offline_access'],
            ],
            'resource_access' => [
                'account' => [
                    'roles' => ['manage-account', 'view-profile'],
                ],
                $this->keycloakClientConfig->getClientId() => [
                    'roles' => ['read'],
                ],
            ],
            'scope' => 'openid profile email',
            'email_verified' => true,
            'preferred_username' => 'authenticator-failure-flow',
            'clientHost' => '127.0.0.1',
            'clientAddress' => '127.0.0.1',
            'client_id' => $this->keycloakClientConfig->getClientId(),
        ];

        foreach ($config->getUserIdentifierJwtClaimNames() as $claimName) {
            $payload[$claimName] = $prefixedIdentifier;
        }

        return $this->encodeJwtPart([
            'alg' => 'RS256',
            'typ' => 'JWT',
            'kid' => 'authenticator-failure-flow-key',
        ]) . '.'
            . $this->encodeJwtPart($payload)
            . '.signature';
    }

    private function expectedStatus(int $defaultStatus): int
    {
        return $this->exposeInfrastructureFailureStatus
            ? $defaultStatus
            : Response::HTTP_UNAUTHORIZED;
    }

    private function buildKeycloakErrorContext(
        int $statusCode,
        string $keycloakError,
        string $keycloakErrorDescription,
        string $correlationIdSuffix,
    ): KeycloakErrorContext {
        return new KeycloakErrorContext(
            method: 'GET',
            uri: rtrim($this->keycloakClientConfig->getBaseUrl(), '/')
                . '/realms/' . $this->keycloakClientConfig->getClientRealm()
                . '/protocol/openid-connect/certs',
            statusCode: $statusCode,
            responseBody: null,
            keycloakError: $keycloakError,
            keycloakErrorDescription: $keycloakErrorDescription,
            correlationId: 'authenticator-failure-flow-' . $correlationIdSuffix,
        );
    }

    private function createVerificationService(callable $handler): KeycloakJwtVerificationServiceInterface
    {
        return new class(Closure::fromCallable($handler)) implements KeycloakJwtVerificationServiceInterface
        {
            public function __construct(
                private readonly Closure $handler,
            ) {
            }

            public function verifyJwt(string $jwt): bool
            {
                return ($this->handler)($jwt);
            }
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encodeJwtPart(array $payload): string
    {
        return rtrim(
            strtr((string) base64_encode((string) json_encode($payload, JSON_THROW_ON_ERROR)), '+/', '-_'),
            '=',
        );
    }
}
