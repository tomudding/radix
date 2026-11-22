<?php

declare(strict_types=1);

namespace App\Message\Scim;

use App\Entity\User\Enums\ScimLocalTypes;

/**
 * A group's full state (name, identifier, membership) must be pushed to the SCIM source.
 *
 * The message carries the local kind and identifier only: the handler loads the fresh state, so
 * a redelivery after a crash or a retry arrives at the same result as the original. The local
 * id is a body's foundation hash or a board drop's key, depending on {@see ScimLocalTypes}.
 */
final readonly class SyncGroupMessage
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
