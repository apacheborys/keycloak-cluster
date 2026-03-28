<?php

declare(strict_types=1);

namespace App\Command;

use App\Keycloak\Fixture\SymfonyFixtureUserStore;
use App\Keycloak\KeycloakRoleManagementFlowService;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(
    name: 'keycloak:role-management:flow',
    description: 'Functional role-management flow via KeycloakServiceInterface: create user, update roles, verify, cleanup'
)]
final class KeycloakRoleManagementFlowCommand extends Command
{
    public function __construct(
        private readonly KeycloakRoleManagementFlowService $flowService,
        private readonly SymfonyFixtureUserStore $fixtureStore,
        private readonly LoggerInterface $logger,
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
            ->addOption('first-name', null, InputOption::VALUE_OPTIONAL, 'First name', 'Role')
            ->addOption('last-name', null, InputOption::VALUE_OPTIONAL, 'Last name', 'Flow')
            ->addOption('email-verified', null, InputOption::VALUE_NONE, 'Mark email as verified')
            ->addOption('disabled', null, InputOption::VALUE_NONE, 'Create user as disabled')
            ->addOption(
                'initial-role',
                null,
                InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                'Initial local roles before update',
                ['ROLE_FLOW_INITIAL']
            )
            ->addOption(
                'updated-role',
                null,
                InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                'Local roles after update',
                ['ROLE_FLOW_UPDATED']
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
            $username = sprintf('kc-role-%s', substr(str_replace('-', '', $runId), 0, 12));
        }

        $email = trim((string) $input->getArgument('email'));
        if ($email === '') {
            $email = sprintf('%s@%s', $username, (string) $input->getOption('email-domain'));
        }

        $password = (string) $input->getArgument('password');
        $firstName = trim((string) $input->getOption('first-name'));
        if ($firstName === '') {
            $firstName = 'Role';
        }

        $lastName = trim((string) $input->getOption('last-name'));
        if ($lastName === '') {
            $lastName = 'Flow';
        }

        $initialRoles = array_values(
            array_filter(
                array_map(static fn (mixed $role): string => trim((string) $role), (array) $input->getOption('initial-role')),
                static fn (string $role): bool => $role !== '',
            )
        );
        if ($initialRoles === []) {
            $initialRoles = ['ROLE_FLOW_INITIAL'];
        }

        $updatedRoles = array_values(
            array_filter(
                array_map(static fn (mixed $role): string => trim((string) $role), (array) $input->getOption('updated-role')),
                static fn (string $role): bool => $role !== '',
            )
        );
        if ($updatedRoles === []) {
            $updatedRoles = ['ROLE_FLOW_UPDATED'];
        }

        try {
            $this->fixtureStore->ensureSchema();

            $fixture = $this->fixtureStore->createFixtureUser(
                id: Uuid::uuid4()->toString(),
                runId: $runId,
                scenario: 'role-management',
                username: $username,
                email: $email,
                plainPassword: $password,
                firstName: $firstName,
                lastName: $lastName,
                emailVerified: (bool) $input->getOption('email-verified'),
                enabled: !$input->getOption('disabled'),
                roles: $initialRoles,
            );
            $fixtureInserted = true;

            $io->section(sprintf('Role-management flow (run_id=%s)', $runId));
            $reportStep = static function (int $stepNumber, string $message) use ($io): void {
                $io->writeln(sprintf('  [%d/4] %s', $stepNumber, $message));
            };

            $result = $this->flowService->run(
                localUser: $fixture->toLocalUser(),
                plainPassword: $fixture->getPlainPassword(),
                updatedRoles: $updatedRoles,
                cleanup: $cleanup,
                reportStep: $reportStep,
            );

            $io->success(sprintf(
                'Role update flow passed for user "%s" (id=%s). Initial roles: [%s], updated roles: [%s].',
                $fixture->getUsername(),
                $result->getCreatedUser()->getId(),
                implode(', ', $result->getInitialRoles()),
                implode(', ', $result->getUpdatedRoles()),
            ));

            return Command::SUCCESS;
        } catch (Throwable $exception) {
            $this->logger->error('Keycloak role-management flow failed.', [
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
                    $this->logger->error('Fixture cleanup failed after role-management flow.', [
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
