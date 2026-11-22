<?php

declare(strict_types=1);

namespace App\Service\Scim;

/**
 * A group as {@see ScimGroupResolver} resolves it: the display name, the external identifier and
 * the local member numbers (lidnrs). The caller turns the numbers into the source's remote ids,
 * because a local id must never reach the wire.
 */
final readonly class ResolvedScimGroup
{
    /**
     * @param list<int> $memberLidnrs
     */
    public function __construct(
        public string $displayName,
        public string $externalId,
        public array $memberLidnrs,
    ) {
    }
}
