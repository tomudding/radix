<?php

declare(strict_types=1);

namespace App\Service\Scim;

/**
 * One of a user's SCIM email attributes.
 */
final readonly class ScimEmail
{
    public function __construct(
        public string $value,
        public bool $primary,
        public ?string $type = null,
    ) {
    }
}
