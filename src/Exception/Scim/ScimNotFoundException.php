<?php

declare(strict_types=1);

namespace App\Exception\Scim;

/**
 * The remote object does not exist (404). Not retryable: repeating the request against a
 * vanished object cannot make it reappear, and what happens next is a policy decision (a delete
 * already treats this as a no-op, an update becomes a create).
 */
class ScimNotFoundException extends ScimException
{
    public function __construct(
        string $endpoint,
        ?string $detail = null,
    ) {
        parent::__construct(
            $endpoint,
            404,
            false,
            $detail,
        );
    }
}
