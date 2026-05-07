<?php

declare(strict_types=1);

namespace App\Command;

use App\Keycloak\Fixture\SymfonyFixtureUserStore;
use App\Keycloak\KeycloakJwtAuthorizationFlowService;
use App\Keycloak\KeycloakPasswordDtoFactory;
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
use Throwable;

#[AsCommand(
    name: 'keycloak:jwt-authorization:flow',
    description: 'Functional JWT flow: create user, login, verify JWT, refresh token, cleanup'
)]
final class KeycloakJwtAuthorizationFlowCommand extends Command
{
    public function __construct(
        private readonly KeycloakJwtAuthorizationFlowService $flowService,
        private readonly KeycloakPasswordDtoFactory $passwordDtoFactory,
        private readonly SymfonyFixtureUserStore $fixtureStore,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(KEYCLOAK_BRIDGE_CLIENT_REALM)%')]
        private readonly string $refreshRealm,
        #[Autowire('%env(KEYCLOAK_BRIDGE_CLIENT_ID)%')]
        private readonly string $refreshClientId,
        #[Autowire('%env(KEYCLOAK_BRIDGE_CLIENT_SECRET)%')]
        private readonly string $refreshClientSecret,
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
            ->addOption('first-name', null, InputOption::VALUE_OPTIONAL, 'First name', 'Jwt')
            ->addOption('last-name', null, InputOption::VALUE_OPTIONAL, 'Last name', 'Flow')
            ->addOption('email-verified', null, InputOption::VALUE_NONE, 'Mark email as verified')
            ->addOption('disabled', null, InputOption::VALUE_NONE, 'Create user as disabled')
            ->addOption('role', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Optional local roles')
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
            $username = sprintf('kc-jwt-%s', substr(str_replace('-', '', $runId), 0, 12));
        }

        $email = trim((string) $input->getArgument('email'));
        if ($email === '') {
            $email = sprintf('%s@%s', $username, (string) $input->getOption('email-domain'));
        }

        $password = (string) $input->getArgument('password');
        $firstName = trim((string) $input->getOption('first-name'));
        if ($firstName === '') {
            $firstName = 'Jwt';
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
                scenario: 'jwt-authorization',
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

            $io->section(sprintf('JWT authorization flow (run_id=%s)', $runId));
            $reportStep = static function (int $stepNumber, string $message) use ($io): void {
                $io->writeln(sprintf('  [%d/6] %s', $stepNumber, $message));
            };

            $result = $this->flowService->runCreateLoginVerifyRefreshDelete(
                localUser: $fixture->toLocalUser(),
                passwordDto: $this->passwordDtoFactory->buildPlain($fixture->getPlainPassword()),
                plainPasswordForLogin: $fixture->getPlainPassword(),
                refreshRealm: $this->refreshRealm,
                refreshClientId: $this->refreshClientId,
                refreshClientSecret: $this->refreshClientSecret,
                cleanup: $cleanup,
                reportStep: $reportStep,
            );

            $accessPayload = $result->getLoginResult()->getAccessToken()->getPayload();
            $refreshedPayload = $result->getRefreshResult()->getAccessToken()->getPayload();

            $io->success(sprintf(
                'JWT flow passed for "%s" (keycloak_id=%s). Access issuer=%s, refreshed issuer=%s, expires_in=%d.',
                $fixture->getUsername(),
                $result->getCreatedUser()->getKeycloakId(),
                $accessPayload->getIss(),
                $refreshedPayload->getIss(),
                $result->getRefreshResult()->getExpiresIn(),
            ));

            return Command::SUCCESS;
        } catch (Throwable $exception) {
            $this->logger->error('Keycloak JWT authorization flow failed.', [
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
                    $this->logger->error('Fixture cleanup failed after JWT authorization flow.', [
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
