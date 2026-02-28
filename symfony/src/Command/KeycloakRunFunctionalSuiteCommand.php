<?php

declare(strict_types=1);

namespace App\Command;

use Apacheborys\KeycloakPhpClient\ValueObject\HashAlgorithm;
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
use Throwable;

#[AsCommand(
    name: 'keycloak:functional-suite',
    description: 'Run functional suite for plain and hashed password flows'
)]
final class KeycloakRunFunctionalSuiteCommand extends Command
{
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
            ->addArgument('base-username', InputArgument::OPTIONAL, 'Base username prefix', 'functional-user')
            ->addArgument('password', InputArgument::OPTIONAL, 'Password used for all tests', 'StrongPass123')
            ->addOption('email-domain', null, InputOption::VALUE_OPTIONAL, 'Email domain', 'example.com')
            ->addOption('first-name', null, InputOption::VALUE_OPTIONAL, 'First name', 'Functional')
            ->addOption('last-name', null, InputOption::VALUE_OPTIONAL, 'Last name', 'Tester')
            ->addOption('email-verified', null, InputOption::VALUE_NONE, 'Mark email as verified');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $baseUsername = (string) $input->getArgument('base-username');
        $password = (string) $input->getArgument('password');
        $emailDomain = (string) $input->getOption('email-domain');
        $firstName = (string) $input->getOption('first-name');
        $lastName = (string) $input->getOption('last-name');
        $emailVerified = (bool) $input->getOption('email-verified');

        $stamp = date('YmdHis');
        if (!is_string($stamp)) {
            $stamp = 'now';
        }

        $scenarios = [
            ['label' => 'plain', 'algorithm' => null],
            ['label' => 'argon', 'algorithm' => HashAlgorithm::ARGON],
            ['label' => 'bcrypt', 'algorithm' => HashAlgorithm::BCRYPT],
            ['label' => 'md5', 'algorithm' => HashAlgorithm::MD5],
        ];

        $failed = 0;
        $passed = 0;

        foreach ($scenarios as $scenario) {
            $label = $scenario['label'];
            $algorithm = $scenario['algorithm'];
            $username = sprintf('%s-%s-%s', $baseUsername, $label, $stamp);
            $email = sprintf('%s@%s', $username, $emailDomain);

            $localUser = new LocalUser(
                username: $username,
                email: $email,
                firstName: $firstName,
                lastName: $lastName,
                enabled: true,
                emailVerified: $emailVerified,
            );

            $passwordDto = $algorithm instanceof HashAlgorithm
                ? $this->passwordDtoFactory->buildHashed($password, $algorithm)
                : $this->passwordDtoFactory->buildPlain($password);

            $io->section(sprintf('Scenario: %s (username=%s)', $label, $username));

            $reportStep = static function (int $stepNumber, string $message) use ($io): void {
                $io->writeln(sprintf('  [%d/5] %s', $stepNumber, $message));
            };

            try {
                $result = $this->flowService->runCreateLoginRefreshDelete(
                    localUser: $localUser,
                    passwordDto: $passwordDto,
                    plainPasswordForLogin: $password,
                    reportStep: $reportStep,
                );

                $io->success(sprintf(
                    'Scenario "%s" passed (id=%s, expires_in=%d).',
                    $label,
                    $result->getCreatedUser()->getId(),
                    $result->getRefreshResult()->getExpiresIn(),
                ));
                $passed++;
            } catch (Throwable $e) {
                $this->logger->error(
                    'Keycloak functional suite scenario failed.',
                    ['scenario' => $label, 'message' => $e->getMessage(), 'exception' => $e]
                );

                $io->error(sprintf('Scenario "%s" failed: %s', $label, $e->getMessage()));
                $failed++;
            }
        }

        if ($failed > 0) {
            $io->warning(sprintf('Suite finished with failures: passed=%d, failed=%d', $passed, $failed));
            return Command::FAILURE;
        }

        $io->success(sprintf('Suite finished successfully: passed=%d, failed=%d', $passed, $failed));
        return Command::SUCCESS;
    }
}
