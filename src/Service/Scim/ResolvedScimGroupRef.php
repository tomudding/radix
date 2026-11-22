<?php

declare(strict_types=1);

namespace App\Service\Scim;

use App\Entity\User\Enums\ScimLocalTypes;

/**
 * A reference to a group a member belongs to, as {@see ScimGroupResolver::refsForMember()}
 * returns them. Carries exactly what a sync message needs: the local kind and the local id.
 */
final readonly class ResolvedScimGroupRef
{
    public function __construct(
        public ScimLocalTypes $localType,
        public string $localId,
    ) {
    }
}
