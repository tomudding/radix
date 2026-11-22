<?php

declare(strict_types=1);

namespace App\Tests\Service\Scim;

use App\Entity\Database\Enums\OrganTypes;
use App\Entity\Decision\AssociationYear;
use App\Service\Scim\ScimGroupKey;
use PHPUnit\Framework\TestCase;

/**
 * The exact group names the consumers are provisioned with. These are the legacy names other
 * systems expect, so each one is pinned here: an active organ keeps its abbreviation, a board
 * year is counted from GEWIS's founding (1982-83 is 1), and the two computed groups have fixed
 * names.
 */
final class ScimGroupKeyTest extends TestCase
{
    public function testAnOrganKeepsItsAbbreviation(): void
    {
        $key = ScimGroupKey::organ(
            OrganTypes::Committee,
            'SKB',
        );

        self::assertSame(
            'ORGAN_SKB',
            $key->displayName,
        );
        self::assertSame(
            'organ:committee:SKB',
            $key->externalId,
        );
    }

    public function testTheBoardYearIsCountedFromTheFounding(): void
    {
        // The 2025-26 association year is the 44th since 1982-83.
        $key = ScimGroupKey::year(AssociationYear::fromYear(2025));

        self::assertSame(
            'BOARD_44',
            $key->displayName,
        );
        self::assertSame(
            'board:44',
            $key->externalId,
        );
    }

    public function testTheFirstBoardYearIsOne(): void
    {
        $key = ScimGroupKey::year(AssociationYear::fromYear(1982));

        self::assertSame(
            'BOARD_1',
            $key->displayName,
        );
    }

    public function testCurrentBoardHasAFixedName(): void
    {
        $key = ScimGroupKey::currentBoard();

        self::assertSame(
            'BOARD_Current',
            $key->displayName,
        );
        self::assertSame(
            'board:current',
            $key->externalId,
        );
    }

    public function testRecentBoardsHasAFixedName(): void
    {
        $key = ScimGroupKey::recentBoards();

        self::assertSame(
            'BOARD_Boards',
            $key->displayName,
        );
        self::assertSame(
            'board:recent',
            $key->externalId,
        );
    }
}
