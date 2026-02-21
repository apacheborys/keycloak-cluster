<?php

declare(strict_types=1);

namespace App\Command;

use Apacheborys\KeycloakPhpClient\DTO\PasswordDto;
use Apacheborys\KeycloakPhpClient\DTO\Request\OidcTokenRequestDto;
use Apacheborys\KeycloakPhpClient\Service\KeycloakServiceInterface;
use Apacheborys\KeycloakPhpClient\ValueObject\OidcGrantType;
use App\Keycloak\LocalUser;
use LogicException;
use Psr\Log\LoggerInterface;
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
    name: 'keycloak:create-user-with-plain-password',
    description: 'Run functional flow: create user, login, refresh token, delete user'
)]
final class KeycloakCreateUserWithPlainPasswordCommand extends Command
{
    public function __construct(
        private readonly KeycloakServiceInterface $keycloakService,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(KEYCLOAK_BRIDGE_CLIENT_REALM)%')]
        private readonly string $clientRealm,
        #[Autowire('%env(KEYCLOAK_BRIDGE_CLIENT_ID)%')]
        private readonly string $clientId,
        #[Autowire('%env(KEYCLOAK_BRIDGE_CLIENT_SECRET)%')]
        private readonly string $clientSecret,
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
        $cleanupUser = null;

        try {
            $io->text('Step 1/5: creating user in Keycloak');
            $createdUser = $this->keycloakService->createUser(
                localUser: $localUser,
                passwordDto: $passwordDto,
            );

            $cleanupUser = new LocalUser(
                username: $localUser->getUsername(),
                email: $localUser->getEmail(),
                firstName: $localUser->getFirstName(),
                lastName: $localUser->getLastName(),
                enabled: $localUser->isEnabled(),
                emailVerified: $localUser->isEmailVerified(),
                roles: $localUser->getRoles(),
                id: $createdUser->getId(),
            );

            $io->text(sprintf('Step 2/5: user exists (id=%s)', $createdUser->getId()));

            if ($createdUser->getUsername() !== $username || $createdUser->getEmail() !== $email) {
                throw new LogicException('Created user verification failed: mismatch in username or email.');
            }

            $io->text('Step 3/5: login with plain password');
            $loginResult = $this->keycloakService->loginUser(
                user: $localUser,
                plainPassword: $plainPassword,
            );

            $refreshToken = $loginResult->getRefreshToken();
            if ($refreshToken === null || $refreshToken === '') {
                throw new LogicException('Login succeeded, but refresh token is missing.');
            }

            $io->text('Step 4/5: refresh token');
            $refreshResult = $this->keycloakService->refreshToken(
                dto: new OidcTokenRequestDto(
                    realm: $this->clientRealm,
                    clientId: $this->clientId,
                    clientSecret: $this->clientSecret,
                    refreshToken: $refreshToken,
                    grantType: OidcGrantType::REFRESH_TOKEN,
                ),
            );

            $io->text(sprintf(
                'Refresh succeeded (token_type=%s, expires_in=%d)',
                $refreshResult->getTokenType(),
                $refreshResult->getExpiresIn(),
            ));

            $io->text('Step 5/5: deleting user');
            $this->keycloakService->deleteUser($cleanupUser);
            $cleanupUser = null;

            $io->success(sprintf(
                'Functional flow passed for "%s": create, verify, login, refresh, delete.',
                $username
            ));

            return Command::SUCCESS;
        } catch (Throwable $e) {
            $this->logger->error(
                'Keycloak functional command failed.',
                ['message' => $e->getMessage(), 'exception' => $e]
            );

            $io->error($e->getMessage());

            if ($cleanupUser instanceof LocalUser) {
                try {
                    $this->keycloakService->deleteUser($cleanupUser);
                    $io->warning('Cleanup succeeded: created user was deleted after failure.');
                } catch (Throwable $cleanupError) {
                    $this->logger->error(
                        'Keycloak user cleanup failed.',
                        ['message' => $cleanupError->getMessage(), 'exception' => $cleanupError]
                    );
                    $io->warning('Cleanup failed: user might still exist in Keycloak.');
                }
            }

            return Command::FAILURE;
        }
    }
}
