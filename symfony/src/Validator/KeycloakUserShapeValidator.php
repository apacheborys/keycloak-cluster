<?php

declare(strict_types=1);

namespace App\Validator;

use Apacheborys\KeycloakPhpClient\Entity\KeycloakUserInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class KeycloakUserShapeValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof KeycloakUserShape) {
            throw new UnexpectedTypeException($constraint, KeycloakUserShape::class);
        }

        if ($value === null) {
            return;
        }

        if (!$value instanceof KeycloakUserInterface) {
            throw new UnexpectedValueException($value, KeycloakUserInterface::class);
        }

        $validator = $this->context->getValidator()->inContext($this->context);

        $validator->atPath('id')->validate(
            $value->getId(),
            [new Assert\NotBlank(message: $constraint->blankIdMessage)],
        );

        $validator->atPath('username')->validate(
            $value->getUsername(),
            [new Assert\NotBlank(message: $constraint->blankUsernameMessage)],
        );

        $validator->atPath('email')->validate(
            $value->getEmail(),
            [
                new Assert\NotBlank(message: $constraint->blankEmailMessage),
                new Assert\Email(message: $constraint->invalidEmailMessage),
            ],
        );

        $validator->atPath('roles')->validate(
            $value->getRoles(),
            [
                new Assert\All([
                    new Assert\NotBlank(message: $constraint->blankRoleMessage),
                ]),
            ],
        );

        $keycloakId = $value->getKeycloakId();
        if ($keycloakId !== null) {
            $validator->atPath('keycloakId')->validate(
                $keycloakId,
                [new Assert\NotBlank(message: $constraint->blankKeycloakIdMessage)],
            );
        }
    }
}
