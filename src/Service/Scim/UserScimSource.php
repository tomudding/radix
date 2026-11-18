<?php

declare(strict_types=1);

namespace App\Service\Scim;

/**
 * The local facts a SCIM user is built from. A plain data holder on purpose: the ledger's
 * member entity stays out of the mapper, which keeps it a pure function.
 */
final readonly class UserScimSource
{
    public function __construct(
        public string $lidnr,
        public string $firstName,
        public string $lastName,
        public ?string $email = null,
        public bool $active = true,
    ) {
    }
}
