<?php

declare(strict_types=1);

namespace App\Service\Scim;

use App\Repository\User\ScimRemoteLinkRepository;
use App\ViewModel\Scim\ScimSyncFailure;
use App\ViewModel\Scim\ScimSyncStatus;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reads the state of the SCIM sync for the administration's sync page: the configuration that
 * governs it, the link counts the source manages, and the pushes that failed. Read-only; the
 * page's actions dispatch messages, they do not run here.
 */
final readonly class ScimSyncStatusProvider
{
    /**
     * The longest failure list the page renders. Failures are the runbook's starting point, and
     * a source that is down would otherwise grow the list without bound.
     */
    private const int MAX_FAILURES = 100;

    public function __construct(
        private ScimRemoteLinkRepository $linkRepository,
        private ScimGroupResolver $groupResolver,
        #[Autowire(env: 'SCIM_AUTHENTIK_BASE_URL')]
        private string $baseUrl,
        private bool $scimEnabled,
    ) {
    }

    public function status(): ScimSyncStatus
    {
        $failing = [];
        foreach ($this->linkRepository->findFailing(self::MAX_FAILURES) as $link) {
            $resolved = $this->groupResolver->resolve(
                $link->localType,
                $link->localId,
            );

            $failing[] = new ScimSyncFailure(
                localType: $link->localType,
                localId: $link->localId,
                displayName: $resolved?->displayName,
                remoteId: $link->remoteId,
                error: (string) $link->lastError,
                lastSyncedAt: $link->lastSyncedAt,
            );
        }

        return new ScimSyncStatus(
            enabled: $this->scimEnabled,
            baseUrl: $this->baseUrl,
            counts: $this->linkRepository->countByType(),
            neverSynced: $this->linkRepository->countNeverSynced(),
            lastSyncedAt: $this->linkRepository->lastSyncedAt(),
            failing: $failing,
        );
    }
}
