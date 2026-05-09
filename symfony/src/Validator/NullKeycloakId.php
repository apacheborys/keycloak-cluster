<?php

declare(strict_types=1);

namespace App\Validator;

use Attribute;
use Symfony\Component\Validator\Constraint;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class NullKeycloakId extends Constraint
{
    public string $message = '{{ label }} fixture user must keep keycloakId=null for fallback testing.';

    public function __construct(
        public string $label = 'Fixture',
        ?string $message = null,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(options: null, groups: $groups, payload: $payload);

        if ($message !== null) {
            $this->message = $message;
        }
    }
}
