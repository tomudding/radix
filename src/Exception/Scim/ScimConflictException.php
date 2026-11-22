<?php

declare(strict_types=1);

namespace App\Exception\Scim;

/**
 * A create collided with an object the SCIM source already manages (409). Not retryable: the
 * conflict is a state question, settled by adopting the existing object rather than by asking
 * again.
 */
class ScimConflictException extends ScimException
{
    public function __construct(
        string $endpoint,
        ?string $detail = null,
    ) {
        parent::__construct(
            $endpoint,
            409,
            false,
            $detail,
        );
    }
}
