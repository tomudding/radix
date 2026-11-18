<?php

declare(strict_types=1);

namespace App\Service\Scim;

use function sort;

/**
 * Maps a local group to the SCIM group it becomes. The members are sorted, so the payload —
 * and its hash, which skips an unchanged sync — does not depend on the order the member ids
 * were read in.
 */
final readonly class GroupScimMapper
{
    public function map(GroupScimSource $source): ScimGroup
    {
        $memberRemoteIds = $source->memberRemoteIds;
        sort($memberRemoteIds);

        return new ScimGroup(
            displayName: $source->displayName,
            externalId: $source->externalId,
            memberRemoteIds: $memberRemoteIds,
        );
    }
}
