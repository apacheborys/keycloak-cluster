<?php

declare(strict_types=1);

namespace App\Command;

use App\Keycloak\Fixture\SymfonyFixtureUserStore;
use App\Keycloak\FixtureUser;
use App\Keycloak\KeycloakLocalIdFallbackFlowService;
use App\Keycloak\LocalIdFallbackFlowInput;
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
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Throwable;

#[AsCommand(
    name: 'keycloak:local-id-fallback:flow',
    description: 'Functional flow for users without persisted Keycloak id: fallback find, update, JWT auth, refresh and delete'
)]
final class KeycloakLocalIdFallbackFlowCommand extends Command
{
    use RendersValidationFailures;

    public function __construct(
        private readonly KeycloakLocalIdFallbackFlowService $flowService,
        private readonly KeycloakPasswordDtoFactory $passwordDtoFactory,
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
            ->addOption('updated-email-domain', null, InputOption::VALUE_OPTIONAL, 'Domain for generated updated email', 'example.com')
            ->addOption('first-name', null, InputOption::VALUE_OPTIONAL, 'Initial first name', 'Fallback')
            ->addOption('last-name', null, InputOption::VALUE_OPTIONAL, 'Initial last name', 'Source')
            ->addOption('updated-first-name', null, InputOption::VALUE_OPTIONAL, 'Updated first name', 'FallbackUpdated')
            ->addOption('updated-last-name', null, InputOption::VALUE_OPTIONAL, 'Updated last name', 'Verified')
            ->addOption('email-verified', null, InputOption::VALUE_NONE, 'Mark email as verified')
            ->addOption('disabled', null, InputOption::VALUE_NONE, 'Create user as disabled')
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
            $username = sprintf('kc-fallback-%s', substr(str_replace('-', '', $runId), 0, 12));
        }

        $email = trim((string) $input->getArgument('email'));
        if ($email === '') {
            $email = sprintf('%s@%s', $username, (string) $input->getOption('email-domain'));
        }

        $password = (string) $input->getArgument('password');
        $firstName = trim((string) $input->getOption('first-name'));
        if ($firstName === '') {
            $firstName = 'Fallback';
        }

        $lastName = trim((string) $input->getOption('last-name'));
        if ($lastName === '') {
            $lastName = 'Source';
        }

        $updatedFirstName = trim((string) $input->getOption('updated-first-name'));
        if ($updatedFirstName === '') {
            $updatedFirstName = 'FallbackUpdated';
        }

        $updatedLastName = trim((string) $input->getOption('updated-last-name'));
        if ($updatedLastName === '') {
            $updatedLastName = 'Verified';
        }

        $updatedEmail = sprintf(
            '%s-updated@%s',
            $username,
            (string) $input->getOption('updated-email-domain'),
        );

        try {
            $this->fixtureStore->ensureSchema();

            $fixture = $this->fixtureStore->createFixtureUser(
                id: Uuid::uuid4()->toString(),
                runId: $runId,
                scenario: 'local-id-fallback',
                username: $username,
                email: $email,
                plainPassword: $password,
                firstName: $firstName,
                lastName: $lastName,
                emailVerified: (bool) $input->getOption('email-verified'),
                enabled: !$input->getOption('disabled'),
                roles: [],
            );
            $fixtureInserted = true;

            $initialUser = $fixture->toFixtureUser();
            $updatedUser = new FixtureUser(
                username: $fixture->getUsername(),
                email: $updatedEmail,
                firstName: $updatedFirstName,
                lastName: $updatedLastName,
                enabled: $fixture->isEnabled(),
                emailVerified: $fixture->isEmailVerified(),
                roles: [],
                id: $fixture->getId(),
                keycloakId: null,
            );

            $io->section(sprintf('Local-id fallback flow (run_id=%s)', $runId));
            $reportStep = static function (int $stepNumber, string $message) use ($io): void {
                $io->writeln(sprintf('  [%d/9] %s', $stepNumber, $message));
            };

            $result = $this->flowService->run(
                input: new LocalIdFallbackFlowInput(
                    initialUser: $initialUser,
                    updatedUser: $updatedUser,
                    passwordDto: $this->passwordDtoFactory->buildPlain($fixture->getPlainPassword()),
                    plainPasswordForLogin: $fixture->getPlainPassword(),
                ),
                cleanup: $cleanup,
                reportStep: $reportStep,
            );

            $io->success(sprintf(
                'Local-id fallback flow passed for "%s" (local_id=%s, keycloak_id=%s, identifier_attribute=%s, identifier_claim=%s, claim_value=%s).',
                $fixture->getUsername(),
                $fixture->getId(),
                $result->getCreatedUser()->getKeycloakId(),
                $result->getIdentifierAttributeName(),
                $result->getIdentifierClaimName(),
                $result->getAccessTokenIdentifierClaimValue(),
            ));

            return Command::SUCCESS;
        } catch (ValidationFailedException $exception) {
            $this->logger->error('Keycloak local-id fallback flow validation failed.', [
                'run_id' => $runId,
                'message' => $exception->getMessage(),
                'violations' => $this->formatValidationViolations($exception),
                'exception' => $exception,
            ]);

            $this->renderValidationFailure($io, $exception, 'Local-id fallback flow input is invalid.');

            return Command::FAILURE;
        } catch (Throwable $exception) {
            $this->logger->error('Keycloak local-id fallback flow failed.', [
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
                    $this->logger->error('Fixture cleanup failed after local-id fallback flow.', [
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
