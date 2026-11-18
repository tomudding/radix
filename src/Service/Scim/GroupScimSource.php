<?php

declare(strict_types=1);

namespace App\Service\Scim;

/**
 * The local facts a SCIM group is built from. Members are remote ids only, resolved through the
 * link table by the caller: a local id must never reach the wire.
 */
final readonly class GroupScimSource
{
    /**
     * @param list<string> $memberRemoteIds
     */
    public function __construct(
        public string $displayName,
        public string $externalId,
        public array $memberRemoteIds = [],
    ) {
    }
}
