<?php

declare(strict_types=1);

namespace App\Command;

use Apacheborys\KeycloakPhpClient\DTO\PasswordDto;
use Apacheborys\KeycloakPhpClient\Service\KeycloakServiceInterface;
use App\Keycloak\LocalUser;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'keycloak:create-user',
    description: 'Create a Keycloak user with a plain password'
)]
final class KeycloakCreateUserCommand extends Command
{
    public function __construct(
        private readonly KeycloakServiceInterface $keycloakService,
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

        $user = new LocalUser(
            username: (string) $input->getArgument('username'),
            email: (string) $input->getArgument('email'),
            firstName: (string) $input->getOption('first-name'),
            lastName: (string) $input->getOption('last-name'),
            enabled: !$input->getOption('disabled'),
            emailVerified: (bool) $input->getOption('email-verified'),
        );

        $passwordDto = new PasswordDto(
            plainPassword: (string) $input->getArgument('password'),
        );

        try {
            $created = $this->keycloakService->createUser(
                localUser: $user,
                passwordDto: $passwordDto,
            );
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf('Created Keycloak user: %s (%s)', $created->getUsername(), $created->getId()));

        return Command::SUCCESS;
    }
}
