<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Scim;

use App\Entity\Database\Enums\MembershipTypes;
use App\Entity\Decision\AssociationYear;
use App\Entity\User\Enums\ScimLocalTypes;
use App\Repository\Decision\BoardMemberRepository;
use App\Repository\Decision\MemberRepository;
use App\Service\Scim\ScimGroupResolver;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;

use function array_unique;
use function array_values;
use function range;

/**
 * The resolver turns a group reference into the name, identifier and membership the projection
 * holds. The board and status drops are the newest kinds, so each is pinned against the seed.
 */
final class ScimGroupResolverTest extends DatabaseTestCase
{
    public function testItResolvesTheCurrentBoardDrop(): void
    {
        $group = self::getContainer()->get(ScimGroupResolver::class)
            ->resolve(
                ScimLocalTypes::Board,
                ScimGroupResolver::BOARD_CURRENT,
            );

        self::assertNotNull($group);
        self::assertSame(
            'BOARD_Current',
            $group->displayName,
        );
        self::assertSame(
            'board:current',
            $group->externalId,
        );
        self::assertSame(
            self::getContainer()->get(BoardMemberRepository::class)->findMembersByDate(new DateTimeImmutable()),
            $group->memberLidnrs,
        );
    }

    public function testItResolvesTheRecentBoardsDropAsTheUnionOfSixYears(): void
    {
        $group = self::getContainer()->get(ScimGroupResolver::class)
            ->resolve(
                ScimLocalTypes::Board,
                ScimGroupResolver::BOARD_RECENT,
            );

        self::assertNotNull($group);
        self::assertSame(
            'BOARD_Boards',
            $group->displayName,
        );

        self::assertEqualsCanonicalizing(
            $this->unionOfBoardYears(AssociationYear::fromDate(new DateTimeImmutable())->getYear()),
            $group->memberLidnrs,
        );
    }

    public function testItResolvesTheGraduatesStatusDrop(): void
    {
        $group = self::getContainer()->get(ScimGroupResolver::class)
            ->resolve(
                ScimLocalTypes::Status,
                ScimGroupResolver::STATUS_GRADUATES,
            );

        self::assertNotNull($group);
        self::assertSame(
            'GRADUATES',
            $group->displayName,
        );
        self::assertSame(
            self::getContainer()->get(MemberRepository::class)->findActiveLidnrsByType(MembershipTypes::Graduate),
            $group->memberLidnrs,
        );
    }

    /**
     * @return list<int>
     */
    private function unionOfBoardYears(int $currentFirstYear): array
    {
        $repo = self::getContainer()->get(BoardMemberRepository::class);
        $memberLidnrs = [];
        foreach (
            range(
                $currentFirstYear - 5,
                $currentFirstYear,
            ) as $firstYear
        ) {
            $memberLidnrs = [
                ...$memberLidnrs,
                ...$repo->findMembersOfAssociationYear($firstYear),
            ];
        }

        return array_values(array_unique($memberLidnrs));
    }
}
