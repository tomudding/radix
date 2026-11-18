<?php

declare(strict_types=1);

namespace App\Message\Scim;

/**
 * A user's account must be pushed to the SCIM source.
 *
 * The message carries the id only: the handler loads the fresh state, so a redelivery after a
 * crash or a retry arrives at the same result as the original.
 */
final readonly class SyncUserMessage
{
    public function __construct(
        private int $lidnr,
    ) {
    }

    public function getLidnr(): int
    {
        return $this->lidnr;
    }
}
