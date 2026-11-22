<?php

declare(strict_types=1);

namespace App\ViewModel\Scim;

use DateTimeImmutable;

/**
 * Everything the sync page shows about the SCIM sync: whether it is on, what the source is, how
 * many objects of each kind it manages, and where it went wrong. Read-only state; the actions
 * live on the page, not here.
 */
final readonly class ScimSyncStatus
{
    /**
     * @param array<string, int>    $counts  the link counts per local kind, keyed by the
     *   {@see \App\Entity\User\Enums\ScimLocalTypes} value
     * @param list<ScimSyncFailure> $failing the failed pushes, newest first
     */
    public function __construct(
        public bool $enabled,
        public string $baseUrl,
        public array $counts,
        public int $neverSynced,
        public ?DateTimeImmutable $lastSyncedAt,
        public array $failing,
    ) {
    }
}
