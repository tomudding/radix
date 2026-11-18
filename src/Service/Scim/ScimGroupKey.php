<?php

declare(strict_types=1);

namespace App\Service\Scim;

use App\Entity\Decision\Organ;
use Symfony\Component\String\Slugger\AsciiSlugger;

use function sprintf;

/**
 * The derivation of the stable identifier (SCIM `externalId`) a group has on the source, from
 * the organ's ledger identity (type and name) rather than the projection's database id, which
 * churns on every nightly rebuild. The projection does not enforce distinct names per type, so
 * `SyncGroupHandler` refuses an organ whose type and name are shared with another organ.
 */
final class ScimGroupKey
{
    public static function for(Organ $organ): string
    {
        $slug = new AsciiSlugger()->slug($organ->name)->toString();

        return sprintf(
            '%s:%s',
            $organ->type->value,
            $slug,
        );
    }
}
