<?php

declare(strict_types=1);

namespace App\Command;

use App\Keycloak\Fixture\SymfonyFixtureUserStore;
use App\Keycloak\JwtAuthorizationFlowInput;
use App\Keycloak\KeycloakJwtAuthorizationFlowService;
use App\Keycloak\KeycloakPasswordDtoFactory;
use LogicException;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Throwable;

#[AsCommand(
    name: 'keycloak:custom-mapper:flow',
    description: 'Functional flow for custom user-entity mapper with JWT login/refresh verification'
)]
final class KeycloakCustomMapperFlowCommand extends Command
{
    use RendersValidationFailures;

    public function __construct(
        private readonly KeycloakJwtAuthorizationFlowService $flowService,
        private readonly KeycloakPasswordDtoFactory $passwordDtoFactory,
        private readonly SymfonyFixtureUserStore $fixtureStore,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(KEYCLOAK_BRIDGE_MAPPER_REALM)%')]
        private readonly string $mapperRealm,
        #[Autowire('%env(KEYCLOAK_BRIDGE_MAPPER_CLIENT_ID)%')]
        private readonly string $mapperClientId,
        #[Autowire('%env(KEYCLOAK_BRIDGE_MAPPER_CLIENT_SECRET)%')]
        private readonly string $mapperClientSecret,
        #[Autowire('%env(KEYCLOAK_BRIDGE_CALLSIGN)%')]
        private readonly string $callsign,
        #[Autowire('%env(KEYCLOAK_BRIDGE_MAPPER_ROLE_PREFIX)%')]
        private readonly string $mapperRolePrefix,
        #[Autowire('%env(KEYCLOAK_BRIDGE_MAPPER_ROLE_SUFFIX)%')]
        private readonly string $mapperRoleSuffix,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::OPTIONAL, 'Username (auto-generated if omitted)', '')
            ->addArgument('email', InputArgument::OPTIONAL, 'Email (auto-generated if omitted)', '')
            ->addArgument('password', InputArgument::OPTIONAL, 'Plain password', 'StrongPass123')
            ->addOption('email-domain', null, InputOption::VALUE_OPTIONAL, 'Domain for generated email', 'example.com')
            ->addOption('first-name', null, InputOption::VALUE_OPTIONAL, 'First name', 'Mapper')
            ->addOption('last-name', null, InputOption::VALUE_OPTIONAL, 'Last name', 'Flow')
            ->addOption('email-verified', null, InputOption::VALUE_NONE, 'Mark email as verified')
            ->addOption('disabled', null, InputOption::VALUE_NONE, 'Create user as disabled')
            ->addOption(
                'role',
                null,
                InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                'Optional local roles (projected by custom mapper)',
                ['ROLE_CUSTOM_MAPPER']
            )
            ->addOption('no-cleanup', null, InputOption::VALUE_NONE, 'Keep created records for debugging');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $cleanup = !$input->getOption('no-cleanup');
        $runId = Uuid::uuid4()->toString();
        $fixtureInserted = false;

        $username = trim((string) $input->getArgument('username'));
        if ($username === '') {
            $username = sprintf('kc-mapper-%s', substr(str_replace('-', '', $runId), 0, 12));
        }

        $email = trim((string) $input->getArgument('email'));
        if ($email === '') {
            $email = sprintf('%s@%s', $username, (string) $input->getOption('email-domain'));
        }

        $password = (string) $input->getArgument('password');
        $firstName = trim((string) $input->getOption('first-name'));
        if ($firstName === '') {
            $firstName = 'Mapper';
        }

        $lastName = trim((string) $input->getOption('last-name'));
        if ($lastName === '') {
            $lastName = 'Flow';
        }

        $roles = array_values(
            array_filter(
                array_map(static fn (mixed $role): string => trim((string) $role), (array) $input->getOption('role')),
                static fn (string $role): bool => $role !== '',
            )
        );

        try {
            $this->fixtureStore->ensureSchema();

            $fixture = $this->fixtureStore->createFixtureUser(
                id: Uuid::uuid4()->toString(),
                runId: $runId,
                scenario: 'custom-mapper',
                username: $username,
                email: $email,
                plainPassword: $password,
                firstName: $firstName,
                lastName: $lastName,
                emailVerified: (bool) $input->getOption('email-verified'),
                enabled: !$input->getOption('disabled'),
                roles: $roles,
            );
            $fixtureInserted = true;

            $io->section(sprintf('Custom mapper flow (run_id=%s)', $runId));
            $reportStep = static function (int $stepNumber, string $message) use ($io): void {
                $io->writeln(sprintf('  [%d/6] %s', $stepNumber, $message));
            };

            $result = $this->flowService->runCreateLoginVerifyRefreshDelete(
                input: new JwtAuthorizationFlowInput(
                    localUser: $fixture->toFixtureUser(),
                    passwordDto: $this->passwordDtoFactory->buildPlain($fixture->getPlainPassword()),
                    plainPasswordForLogin: $fixture->getPlainPassword(),
                    refreshRealm: $this->mapperRealm,
                    refreshClientId: $this->mapperClientId,
                    refreshClientSecret: $this->mapperClientSecret,
                ),
                cleanup: $cleanup,
                reportStep: $reportStep,
            );

            $issuer = $result->getLoginResult()->getAccessToken()->getPayload()->getIss();
            $expectedRealmSegment = '/realms/' . $this->mapperRealm;
            if (!str_contains($issuer, $expectedRealmSegment)) {
                throw new LogicException(sprintf(
                    'Unexpected issuer "%s". Expected to contain "%s".',
                    $issuer,
                    $expectedRealmSegment,
                ));
            }

            $payload = $result->getLoginResult()->getAccessToken()->getPayload();
            if ($payload->getAzp() !== $this->mapperClientId) {
                throw new LogicException(sprintf(
                    'Custom mapper login did not use expected client_id. Expected "%s", got "%s".',
                    $this->mapperClientId,
                    $payload->getAzp(),
                ));
            }

            $jwtRoles = $payload->getRealmAccess()['roles'];
            $normalizedCallsign = rtrim(trim($this->callsign), '.');
            foreach ($fixture->getRoles() as $localRole) {
                $projectedRole = $normalizedCallsign . '.'
                    . $this->mapperRolePrefix
                    . $localRole
                    . $this->mapperRoleSuffix;
                if (!in_array($projectedRole, $jwtRoles, true)) {
                    throw new LogicException(sprintf(
                        'Projected role "%s" was not found in JWT roles. JWT roles: [%s].',
                        $projectedRole,
                        implode(', ', $jwtRoles),
                    ));
                }
            }

            $io->success(sprintf(
                'Custom mapper flow passed for "%s" (keycloak_id=%s, issuer=%s, azp=%s).',
                $fixture->getUsername(),
                $result->getCreatedUser()->getKeycloakId(),
                $issuer,
                $payload->getAzp(),
            ));

            return Command::SUCCESS;
        } catch (ValidationFailedException $exception) {
            $this->logger->error('Keycloak custom mapper flow validation failed.', [
                'run_id' => $runId,
                'message' => $exception->getMessage(),
                'violations' => $this->formatValidationViolations($exception),
                'exception' => $exception,
            ]);

            $this->renderValidationFailure($io, $exception, 'Custom mapper flow input is invalid.');

            return Command::FAILURE;
        } catch (Throwable $exception) {
            $this->logger->error('Keycloak custom mapper flow failed.', [
                'run_id' => $runId,
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);

            $io->error($exception->getMessage());

            return Command::FAILURE;
        } finally {
            if ($cleanup && $fixtureInserted) {
                try {
                    $deleted = $this->fixtureStore->cleanupByRunId($runId);
                    $io->writeln(sprintf('  [db] Cleanup completed, removed fixture rows: %d', $deleted));
                } catch (Throwable $cleanupException) {
                    $this->logger->error('Fixture cleanup failed after custom mapper flow.', [
                        'run_id' => $runId,
                        'message' => $cleanupException->getMessage(),
                        'exception' => $cleanupException,
                    ]);
                    $io->warning(sprintf('Fixture cleanup failed: %s', $cleanupException->getMessage()));
                }
            }
        }
    }
}
