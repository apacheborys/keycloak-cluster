<?php

declare(strict_types=1);

namespace App\Command;

use Apacheborys\KeycloakPhpClient\DTO\PasswordDto;
use Apacheborys\KeycloakPhpClient\ValueObject\HashAlgorithm;
use App\Keycloak\KeycloakFunctionalFlowService;
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
use Throwable;

#[AsCommand(
    name: 'keycloak:create-user-with-hashed-password',
    description: 'Run functional flow with hashed password: create user, login, refresh token, delete user'
)]
final class KeycloakCreateUserWithHashedPasswordCommand extends Command
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
            ->addArgument('password', InputArgument::REQUIRED, 'Plain password (used to build hash and for login)')
            ->addArgument('algorithm', InputArgument::REQUIRED, 'Hash algorithm: argon, bcrypt, md5')
            ->addOption('first-name', null, InputOption::VALUE_OPTIONAL, 'First name', '')
            ->addOption('last-name', null, InputOption::VALUE_OPTIONAL, 'Last name', '')
            ->addOption('email-verified', null, InputOption::VALUE_NONE, 'Mark email as verified')
            ->addOption('disabled', null, InputOption::VALUE_NONE, 'Create user disabled');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $username = (string) $input->getArgument('username');
        $plainPassword = (string) $input->getArgument('password');
        $algorithmInput = (string) $input->getArgument('algorithm');

        $localUser = new LocalUser(
            username: $username,
            email: (string) $input->getArgument('email'),
            firstName: (string) $input->getOption('first-name'),
            lastName: (string) $input->getOption('last-name'),
            enabled: !$input->getOption('disabled'),
            emailVerified: (bool) $input->getOption('email-verified'),
        );

        try {
            $hashAlgorithm = $this->resolveHashAlgorithm($algorithmInput);
            $passwordDto = $this->buildHashedPasswordDto($plainPassword, $hashAlgorithm);

            $result = $this->flowService->runCreateLoginRefreshDelete(
                localUser: $localUser,
                passwordDto: $passwordDto,
                plainPasswordForLogin: $plainPassword,
            );

            $io->success(sprintf(
                'Functional flow passed for "%s" with "%s" (id=%s, token_expires_in=%d).',
                $username,
                $hashAlgorithm->value,
                $result->getCreatedUser()->getId(),
                $result->getRefreshResult()->getExpiresIn(),
            ));

            return Command::SUCCESS;
        } catch (Throwable $e) {
            $this->logger->error(
                'Keycloak hashed functional command failed.',
                ['message' => $e->getMessage(), 'exception' => $e]
            );

            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }

    private function resolveHashAlgorithm(string $algorithm): HashAlgorithm
    {
        return match (strtolower($algorithm)) {
            'argon', 'argon2', 'argon2id' => HashAlgorithm::ARGON,
            'bcrypt' => HashAlgorithm::BCRYPT,
            'md5' => HashAlgorithm::MD5,
            default => throw new LogicException(
                sprintf('Unsupported algorithm "%s". Use one of: argon, bcrypt, md5.', $algorithm)
            ),
        };
    }

    private function buildHashedPasswordDto(string $plainPassword, HashAlgorithm $algorithm): PasswordDto
    {
        return match ($algorithm) {
            HashAlgorithm::MD5 => $this->buildMd5PasswordDto($plainPassword),
            HashAlgorithm::BCRYPT => $this->buildBcryptPasswordDto($plainPassword),
            HashAlgorithm::ARGON => $this->buildArgonPasswordDto($plainPassword),
        };
    }

    private function buildMd5PasswordDto(string $plainPassword): PasswordDto
    {
        return new PasswordDto(
            hashedPassword: md5($plainPassword),
            hashAlgorithm: HashAlgorithm::MD5,
        );
    }

    private function buildBcryptPasswordDto(string $plainPassword): PasswordDto
    {
        $cost = 13;
        $hash = password_hash($plainPassword, PASSWORD_BCRYPT, ['cost' => $cost]);

        if ($hash === false) {
            throw new LogicException('Failed to generate bcrypt hash.');
        }

        return new PasswordDto(
            hashedPassword: $hash,
            hashAlgorithm: HashAlgorithm::BCRYPT,
            hashIterations: $cost,
            hashSalt: '',
        );
    }

    private function buildArgonPasswordDto(string $plainPassword): PasswordDto
    {
        if (!defined('PASSWORD_ARGON2ID')) {
            throw new LogicException('Argon2id is not available in this PHP build.');
        }

        $timeCost = PASSWORD_ARGON2_DEFAULT_TIME_COST;
        $hash = password_hash(
            $plainPassword,
            PASSWORD_ARGON2ID,
            [
                'time_cost' => $timeCost,
                'memory_cost' => PASSWORD_ARGON2_DEFAULT_MEMORY_COST,
                'threads' => PASSWORD_ARGON2_DEFAULT_THREADS,
            ]
        );

        if ($hash === false) {
            throw new LogicException('Failed to generate argon hash.');
        }

        return new PasswordDto(
            hashedPassword: $hash,
            hashAlgorithm: HashAlgorithm::ARGON,
            hashIterations: $timeCost,
            hashSalt: '',
        );
    }
}
