<?php

declare(strict_types=1);

namespace App\Tests\Service\Scim;

use App\Service\Scim\GroupScimMapper;
use App\Service\Scim\GroupScimSource;
use PHPUnit\Framework\TestCase;

/**
 * The group mapper is deliberately thin: it passes the group through, sorts the membership so
 * the payload does not depend on read order, and the tests pin the rules that matter — the
 * stable externalId travels on every group, and the membership is remote ids only.
 */
final class GroupScimMapperTest extends TestCase
{
    public function testMapsTheGroupWithItsRemoteMembership(): void
    {
        $mapped = new GroupScimMapper()->map(new GroupScimSource(
            displayName: 'Commissie Bestuursondersteuning',
            externalId: 'committee:bestuursondersteuning',
            memberRemoteIds: [
                'uuid-1',
                'uuid-2',
            ],
        ));

        self::assertSame(
            'Commissie Bestuursondersteuning',
            $mapped->displayName,
        );
        self::assertSame(
            'committee:bestuursondersteuning',
            $mapped->externalId,
        );
        self::assertSame(
            [
                'uuid-1',
                'uuid-2',
            ],
            $mapped->memberRemoteIds,
        );
        self::assertNull($mapped->remoteId);
    }

    public function testAGroupWithoutMembersCarriesAnEmptyMembership(): void
    {
        $mapped = new GroupScimMapper()->map(new GroupScimSource(
            displayName: 'Commissie Bestuursondersteuning',
            externalId: 'committee:bestuursondersteuning',
        ));

        self::assertSame(
            [],
            $mapped->memberRemoteIds,
        );
    }

    public function testTheMembershipIsSortedRegardlessOfReadOrder(): void
    {
        $source = new GroupScimSource(
            displayName: 'Commissie Bestuursondersteuning',
            externalId: 'committee:bestuursondersteuning',
            memberRemoteIds: [
                'uuid-3',
                'uuid-1',
                'uuid-2',
            ],
        );

        self::assertSame(
            new GroupScimMapper()->map($source)->payloadHash(),
            new GroupScimMapper()->map(new GroupScimSource(
                displayName: 'Commissie Bestuursondersteuning',
                externalId: 'committee:bestuursondersteuning',
                memberRemoteIds: [
                    'uuid-2',
                    'uuid-3',
                    'uuid-1',
                ],
            ))->payloadHash(),
        );
        self::assertSame(
            [
                'uuid-1',
                'uuid-2',
                'uuid-3',
            ],
            new GroupScimMapper()->map($source)->memberRemoteIds,
        );
    }
}
