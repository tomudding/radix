<?php

declare(strict_types=1);

namespace App\Tests\Service\Scim;

use App\Entity\Database\Enums\BoardFunctions;
use App\Entity\Database\Enums\InstallationFunctions;
use App\Entity\Decision\AssociationYear;
use App\Service\Scim\ScimGroupKey;
use PHPUnit\Framework\TestCase;

/**
 * The exact group names the consumers are provisioned with. These are the legacy names other
 * systems expect, so each one is pinned here: a body uses its abbreviation, an abrogated body
 * the HIST_ marker and the abrogation year, and a board year is counted from GEWIS's founding
 * (1982-83 is 1).
 */
final class ScimGroupKeyTest extends TestCase
{
    public function testAnActiveBodyKeepsItsAbbreviation(): void
    {
        $key = ScimGroupKey::activeBody(
            'SKB',
            'ALV-5.2.3.1',
        );

        self::assertSame(
            'BODY_SKB',
            $key->displayName,
        );
        // The externalId is the foundation hash, which survives a later body reusing the letters.
        self::assertSame(
            'BODY:ALV-5.2.3.1',
            $key->externalId,
        );
    }

    public function testAnAbrogatedBodyAddsTheHistMarkerAndYear(): void
    {
        $key = ScimGroupKey::abrogatedBody(
            'SKB',
            'ALV-5.2.3.1',
            1990,
        );

        self::assertSame(
            'HIST_BODY_SKB (1990)',
            $key->displayName,
        );
        self::assertSame(
            'BODY:ALV-5.2.3.1',
            $key->externalId,
        );
    }

    public function testAnAbrogatedBodyKeepsItsFoundationExternalId(): void
    {
        // The same body's externalId does not change when it is abrogated: the source correlates
        // on it, so a shifted group is the same group.
        self::assertSame(
            ScimGroupKey::activeBody(
                'SKB',
                'ALV-5.2.3.1',
            )->externalId,
            ScimGroupKey::abrogatedBody(
                'SKB',
                'ALV-5.2.3.1',
                1990,
            )->externalId,
        );
    }

    public function testABodyFunctionUsesTheEnglishNameAndDutchValue(): void
    {
        $key = ScimGroupKey::bodyFunction(
            'SKB',
            'ALV-5.2.3.1',
            InstallationFunctions::Chair,
        );

        self::assertSame(
            'BODY_SKB_Chair',
            $key->displayName,
        );
        self::assertSame(
            'BODY:ALV-5.2.3.1:Voorzitter',
            $key->externalId,
        );
    }

    public function testAnInactiveBodyIsASeparateDrop(): void
    {
        $key = ScimGroupKey::inactiveBody(
            'SKB',
            'ALV-5.2.3.1',
        );

        self::assertSame(
            'BODY_SKB_Inactive',
            $key->displayName,
        );
        self::assertSame(
            'BODY:ALV-5.2.3.1:inactive',
            $key->externalId,
        );
    }

    public function testTheBoardYearIsCountedFromTheFounding(): void
    {
        // The 2025-26 association year is the 44th since 1982-83.
        $key = ScimGroupKey::boardYear(AssociationYear::fromYear(2025));

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
        $key = ScimGroupKey::boardYear(AssociationYear::fromYear(1982));

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

    public function testKeyholdersHasAFixedName(): void
    {
        $key = ScimGroupKey::keyholders();

        self::assertSame(
            'KEYHOLDERS',
            $key->displayName,
        );
        self::assertSame(
            'STATUS:keyholders',
            $key->externalId,
        );
    }

    public function testABoardFunctionKeepsItsEnglishName(): void
    {
        $key = ScimGroupKey::boardFunction(BoardFunctions::Secretary);

        self::assertSame(
            'BOARD_Secretary',
            $key->displayName,
        );
        self::assertSame(
            'board:secretary',
            $key->externalId,
        );
    }

    public function testABoardFunctionPlusMarksTheReleasedTransition(): void
    {
        $key = ScimGroupKey::boardFunction(
            BoardFunctions::Treasurer,
            plus: true,
        );

        self::assertSame(
            'BOARD_TreasurerPlus',
            $key->displayName,
        );
        self::assertSame(
            'board:treasurer:plus',
            $key->externalId,
        );
    }
}
