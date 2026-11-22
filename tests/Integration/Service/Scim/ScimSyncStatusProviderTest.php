<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Scim;

use App\Entity\User\Enums\ScimLocalTypes;
use App\Entity\User\ScimRemoteLink;
use App\Repository\User\UserRepository;
use App\Service\Scim\ResolvedScimGroupRef;
use App\Service\Scim\ScimGroupResolver;
use App\Service\Scim\ScimSyncStatusProvider;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;

use function array_sum;

/**
 * What the administration's sync page reads from the link table: the counts, the newest push,
 * the pushes that failed and the ones that never completed. The links are written by the sync
 * handlers, so the seed holds none; each test builds the state it reports.
 */
#[CoversClass(ScimSyncStatusProvider::class)]
final class ScimSyncStatusProviderTest extends DatabaseTestCase
{
    private ScimSyncStatusProvider $provider;
    private ScimGroupResolver $groupResolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = self::getContainer()->get(ScimSyncStatusProvider::class);
        $this->groupResolver = self::getContainer()->get(ScimGroupResolver::class);
    }

    public function testAnEmptyLinkTableReportsNothingSynced(): void
    {
        $status = $this->provider->status();

        self::assertTrue($status->enabled);
        // The base URL is the environment's configuration, not behaviour; only that it is passed
        // through matters here.
        self::assertNotSame(
            '',
            $status->baseUrl,
        );
        self::assertSame(
            [],
            $status->counts,
        );
        self::assertSame(
            0,
            $status->neverSynced,
        );
        self::assertNull($status->lastSyncedAt);
        self::assertSame(
            [],
            $status->failing,
        );
    }

    public function testItCountsTheLinksAndReportsTheProblems(): void
    {
        $lidnr = self::getContainer()->get(UserRepository::class)->findAll()[0]->lidnr;
        $bodyRef = $this->aBodyGroupRef();

        $this->persistLink(
            ScimLocalTypes::User,
            (string) $lidnr,
            'remote-user',
            new DateTimeImmutable('2025-01-02 00:00:00'),
        );
        $this->persistLink(
            $bodyRef->localType,
            $bodyRef->localId,
            'remote-body',
            new DateTimeImmutable('2025-01-03 00:00:00'),
        );
        // A board link that was never synced: a push was dispatched but never completed.
        $this->persistLink(
            ScimLocalTypes::Board,
            ScimGroupResolver::BOARD_CURRENT,
            'remote-board',
            null,
        );
        $failing = $this->persistLink(
            ScimLocalTypes::Status,
            ScimGroupResolver::STATUS_MEMBERS,
            'remote-status',
            new DateTimeImmutable('2025-01-01 00:00:00'),
        );
        $failing->lastError = 'the source refused the request';
        $this->entityManager->flush();

        $status = $this->provider->status();

        self::assertSame(
            4,
            array_sum($status->counts),
        );
        self::assertSame(
            1,
            $status->counts[ScimLocalTypes::User->value],
        );
        self::assertSame(
            1,
            $status->counts[ScimLocalTypes::Body->value],
        );
        self::assertSame(
            1,
            $status->counts[ScimLocalTypes::Board->value],
        );
        self::assertSame(
            1,
            $status->counts[ScimLocalTypes::Status->value],
        );
        self::assertSame(
            1,
            $status->neverSynced,
        );
        self::assertSame(
            '2025-01-03 00:00:00',
            $status->lastSyncedAt?->format('Y-m-d H:i:s'),
        );

        self::assertCount(
            1,
            $status->failing,
        );
        $failure = $status->failing[0];
        self::assertSame(
            ScimLocalTypes::Status,
            $failure->localType,
        );
        self::assertSame(
            'remote-status',
            $failure->remoteId,
        );
        self::assertSame(
            'the source refused the request',
            $failure->error,
        );
        // The failure's display name is resolved from the projection, like the page shows it.
        self::assertSame(
            'MEMBERS',
            $failure->displayName,
        );
    }

    private function persistLink(
        ScimLocalTypes $type,
        string $localId,
        string $remoteId,
        ?DateTimeImmutable $syncedAt,
    ): ScimRemoteLink {
        $link = new ScimRemoteLink(
            $type,
            $localId,
            $remoteId,
        );
        $link->payloadHash = 'hash-' . $remoteId;
        $link->lastSyncedAt = $syncedAt;
        $this->entityManager->persist($link);

        return $link;
    }

    private function aBodyGroupRef(): ResolvedScimGroupRef
    {
        foreach ($this->groupResolver->allGroupRefs() as $ref) {
            if (ScimLocalTypes::Body === $ref->localType) {
                return $ref;
            }
        }

        self::markTestSkipped('No body group in the seed.');
    }
}
