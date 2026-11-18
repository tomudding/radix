<?php

declare(strict_types=1);

namespace App\Message\Scim;

/**
 * The membership of a group on the SCIM source must be brought in line with the local state.
 *
 * The message carries the id only: the handler loads the fresh state, so a redelivery after a
 * crash or a retry arrives at the same result as the original.
 */
final readonly class SyncGroupMembershipMessage
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
