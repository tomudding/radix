<?php

declare(strict_types=1);

namespace App\Message\Scim;

use App\Entity\User\Enums\ScimLocalTypes;

/**
 * The membership of a group on the SCIM source must be brought in line with the local state.
 *
 * The message carries the local kind and identifier only: the handler loads the fresh state, so
 * a redelivery after a crash or a retry arrives at the same result as the original.
 */
final readonly class SyncGroupMembershipMessage
{
    public function __construct(
        private ScimLocalTypes $localType,
        private string $localId,
    ) {
    }

    public function getLocalType(): ScimLocalTypes
    {
        return $this->localType;
    }

    public function getLocalId(): string
    {
        return $this->localId;
    }
}
