<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;

trait RendersValidationFailures
{
    /**
     * @return list<string>
     */
    private function formatValidationViolations(ValidationFailedException $exception): array
    {
        return array_values(array_map(
            static fn (ConstraintViolationInterface $violation): string => sprintf(
                '%s%s',
                $violation->getPropertyPath() !== '' ? $violation->getPropertyPath() . ': ' : '',
                $violation->getMessage(),
            ),
            iterator_to_array($exception->getViolations()),
        ));
    }

    private function renderValidationFailure(
        SymfonyStyle $io,
        ValidationFailedException $exception,
        string $headline,
    ): void {
        $io->error($headline);

        foreach ($this->formatValidationViolations($exception) as $violationMessage) {
            $io->writeln('  - ' . $violationMessage);
        }
    }
}
