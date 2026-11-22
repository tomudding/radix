<?php

declare(strict_types=1);

namespace App\ViewModel\Scim;

use App\Entity\User\Enums\ScimLocalTypes;
use DateTimeImmutable;

/**
 * One failed push as the sync page shows it: the object, its remote counterpart, and the error
 * text the runbook starts from. The display name is resolved from the projection when the local
 * object still exists, so a failing body reads as its name rather than its foundation hash.
 */
final readonly class ScimSyncFailure
{
    public function __construct(
        public ScimLocalTypes $localType,
        public string $localId,
        public ?string $displayName,
        public string $remoteId,
        public string $error,
        public ?DateTimeImmutable $lastSyncedAt,
    ) {
    }
}
