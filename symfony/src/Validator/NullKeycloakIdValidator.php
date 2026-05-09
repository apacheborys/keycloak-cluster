<?php

declare(strict_types=1);

namespace App\Validator;

use Apacheborys\KeycloakPhpClient\Entity\KeycloakUserInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class NullKeycloakIdValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof NullKeycloakId) {
            throw new UnexpectedTypeException($constraint, NullKeycloakId::class);
        }

        if ($value === null) {
            return;
        }

        if (!$value instanceof KeycloakUserInterface) {
            throw new UnexpectedValueException($value, KeycloakUserInterface::class);
        }

        if ($value->getKeycloakId() !== null) {
            $this->context
                ->buildViolation($constraint->message)
                ->setParameter('{{ label }}', $constraint->label)
                ->atPath('keycloakId')
                ->addViolation();
        }
    }
}
