<?php

declare(strict_types=1);

namespace App\Message\Scim;

/**
 * A group's full state (name, identifier, membership) must be pushed to the SCIM source.
 *
 * The message carries the id only: the handler loads the fresh state, so a redelivery after a
 * crash or a retry arrives at the same result as the original.
 */
final readonly class SyncGroupMessage
{
    public function __construct(
        private int $organId,
    ) {
    }

    public function getOrganId(): int
    {
        return $this->organId;
    }
}
