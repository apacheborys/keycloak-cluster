<?php

declare(strict_types=1);

namespace App\Command;

use Apacheborys\KeycloakPhpClient\DTO\PasswordDto;
use App\Keycloak\KeycloakFunctionalFlowService;
use App\Keycloak\LocalUser;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(
    name: 'keycloak:create-user-with-plain-password',
    description: 'Run functional flow: create user, login, refresh token, delete user'
)]
final class KeycloakCreateUserWithPlainPasswordCommand extends Command
{
    public function __construct(
        private readonly KeycloakFunctionalFlowService $flowService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::REQUIRED, 'Username')
            ->addArgument('email', InputArgument::REQUIRED, 'Email')
            ->addArgument('password', InputArgument::REQUIRED, 'Plain password')
            ->addOption('first-name', null, InputOption::VALUE_OPTIONAL, 'First name', '')
            ->addOption('last-name', null, InputOption::VALUE_OPTIONAL, 'Last name', '')
            ->addOption('email-verified', null, InputOption::VALUE_NONE, 'Mark email as verified')
            ->addOption('disabled', null, InputOption::VALUE_NONE, 'Create user disabled');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $username = (string) $input->getArgument('username');
        $email = (string) $input->getArgument('email');
        $plainPassword = (string) $input->getArgument('password');

        $localUser = new LocalUser(
            username: $username,
            email: $email,
            firstName: (string) $input->getOption('first-name'),
            lastName: (string) $input->getOption('last-name'),
            enabled: !$input->getOption('disabled'),
            emailVerified: (bool) $input->getOption('email-verified'),
        );

        $passwordDto = new PasswordDto(plainPassword: $plainPassword);

        try {
            $result = $this->flowService->runCreateLoginRefreshDelete(
                localUser: $localUser,
                passwordDto: $passwordDto,
                plainPasswordForLogin: $plainPassword,
            );

            $io->success(sprintf(
                'Functional flow passed for "%s" (id=%s, token_expires_in=%d).',
                $username,
                $result->getCreatedUser()->getId(),
                $result->getRefreshResult()->getExpiresIn(),
            ));

            return Command::SUCCESS;
        } catch (Throwable $e) {
            $this->logger->error(
                'Keycloak functional command failed.',
                ['message' => $e->getMessage(), 'exception' => $e]
            );

            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }
}
