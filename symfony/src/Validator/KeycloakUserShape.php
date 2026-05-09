<?php

declare(strict_types=1);

namespace App\Validator;

use Attribute;
use Symfony\Component\Validator\Constraint;

#[Attribute(Attribute::TARGET_CLASS)]
final class KeycloakUserShape extends Constraint
{
    public string $blankIdMessage = 'Local user id must not be blank.';

    public string $blankUsernameMessage = 'Username must not be blank.';

    public string $blankEmailMessage = 'Email must not be blank.';

    public string $invalidEmailMessage = 'Email must be a valid email address.';

    public string $blankRoleMessage = 'Role names must not be blank.';

    public string $blankKeycloakIdMessage = 'Keycloak id must not be blank when provided.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
