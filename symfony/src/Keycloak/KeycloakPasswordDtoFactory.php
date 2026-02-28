<?php

declare(strict_types=1);

namespace App\Keycloak;

use Apacheborys\KeycloakPhpClient\DTO\PasswordDto;
use Apacheborys\KeycloakPhpClient\ValueObject\HashAlgorithm;
use LogicException;

final readonly class KeycloakPasswordDtoFactory
{
    public function buildPlain(string $plainPassword): PasswordDto
    {
        return new PasswordDto(plainPassword: $plainPassword);
    }

    public function buildHashed(string $plainPassword, HashAlgorithm $algorithm): PasswordDto
    {
        return match ($algorithm) {
            HashAlgorithm::MD5 => $this->buildMd5($plainPassword),
            HashAlgorithm::BCRYPT => $this->buildBcrypt($plainPassword),
            HashAlgorithm::ARGON => $this->buildArgon($plainPassword),
        };
    }

    public function resolveAlgorithm(string $algorithm): HashAlgorithm
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

    private function buildMd5(string $plainPassword): PasswordDto
    {
        return new PasswordDto(
            hashedPassword: md5($plainPassword),
            hashAlgorithm: HashAlgorithm::MD5,
        );
    }

    private function buildBcrypt(string $plainPassword): PasswordDto
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

    private function buildArgon(string $plainPassword): PasswordDto
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
