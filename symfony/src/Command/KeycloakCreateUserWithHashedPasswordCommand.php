<?php

declare(strict_types=1);

namespace App\Command;

use App\Keycloak\FunctionalFlowInput;
use App\Keycloak\KeycloakFunctionalFlowService;
use App\Keycloak\KeycloakPasswordDtoFactory;
use App\Keycloak\LocalUser;
use Psr\Log\LoggerInterface;
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
    name: 'keycloak:create-user-with-hashed-password',
    description: 'Run functional flow with hashed password: create user, login, refresh token, delete user'
)]
final class KeycloakCreateUserWithHashedPasswordCommand extends Command
{
    use RendersValidationFailures;

    public function __construct(
        private readonly KeycloakFunctionalFlowService $flowService,
        private readonly KeycloakPasswordDtoFactory $passwordDtoFactory,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::REQUIRED, 'Username')
            ->addArgument('email', InputArgument::REQUIRED, 'Email')
            ->addArgument('password', InputArgument::REQUIRED, 'Plain password (used to build hash and for login)')
            ->addArgument('algorithm', InputArgument::REQUIRED, 'Hash algorithm: argon, bcrypt, md5')
            ->addOption('first-name', null, InputOption::VALUE_OPTIONAL, 'First name', 'Test')
            ->addOption('last-name', null, InputOption::VALUE_OPTIONAL, 'Last name', 'User')
            ->addOption('email-verified', null, InputOption::VALUE_NONE, 'Mark email as verified')
            ->addOption('disabled', null, InputOption::VALUE_NONE, 'Create user disabled');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $username = (string) $input->getArgument('username');
        $plainPassword = (string) $input->getArgument('password');
        $algorithmInput = (string) $input->getArgument('algorithm');
        $firstName = trim((string) $input->getOption('first-name'));
        if ($firstName === '') {
            $firstName = 'Test';
        }

        $lastName = trim((string) $input->getOption('last-name'));
        if ($lastName === '') {
            $lastName = 'User';
        }

        $localUser = new LocalUser(
            username: $username,
            email: (string) $input->getArgument('email'),
            firstName: $firstName,
            lastName: $lastName,
            enabled: !$input->getOption('disabled'),
            emailVerified: (bool) $input->getOption('email-verified'),
        );

        try {
            $hashAlgorithm = $this->passwordDtoFactory->resolveAlgorithm($algorithmInput);
            $passwordDto = $this->passwordDtoFactory->buildHashed($plainPassword, $hashAlgorithm);
            $reportStep = static function (int $stepNumber, string $message) use ($io): void {
                $io->writeln(sprintf('  [%d/5] %s', $stepNumber, $message));
            };

            $io->section(sprintf('Functional flow (hashed: %s)', $hashAlgorithm->value));
            $result = $this->flowService->runCreateLoginRefreshDelete(
                input: new FunctionalFlowInput(
                    localUser: $localUser,
                    passwordDto: $passwordDto,
                    plainPasswordForLogin: $plainPassword,
                ),
                reportStep: $reportStep,
            );

            $io->success(sprintf(
                'Functional flow passed for "%s" with "%s" (keycloak_id=%s, token_expires_in=%d).',
                $username,
                $hashAlgorithm->value,
                $result->getCreatedUser()->getKeycloakId(),
                $result->getRefreshResult()->getExpiresIn(),
            ));

            return Command::SUCCESS;
        } catch (ValidationFailedException $e) {
            $this->logger->error(
                'Keycloak hashed functional command validation failed.',
                [
                    'message' => $e->getMessage(),
                    'violations' => $this->formatValidationViolations($e),
                    'exception' => $e,
                ]
            );

            $this->renderValidationFailure($io, $e, 'Functional flow input is invalid.');

            return Command::FAILURE;
        } catch (Throwable $e) {
            $this->logger->error(
                'Keycloak hashed functional command failed.',
                ['message' => $e->getMessage(), 'exception' => $e]
            );

            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }
}
