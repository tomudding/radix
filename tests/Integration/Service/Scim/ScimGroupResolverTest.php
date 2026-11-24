<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Scim;

use App\DataFixtures\Member\MemberPopulationFixture;
use App\Entity\Database\Enums\InstallationFunctions;
use App\Entity\Database\Enums\MembershipTypes;
use App\Entity\Decision\AssociationYear;
use App\Entity\Decision\BoardMember;
use App\Entity\Decision\Keyholder;
use App\Entity\User\Enums\ScimLocalTypes;
use App\Repository\Decision\BoardMemberRepository;
use App\Repository\Decision\KeyholderRepository;
use App\Repository\Decision\MemberRepository;
use App\Repository\Decision\OrganMemberRepository;
use App\Service\Scim\ScimGroupResolver;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;

use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function range;

/**
 * The resolver turns a group reference into the name, identifier and membership the projection
 * holds. The board and status drops are the newest kinds, so each is pinned against the seed.
 */
final class ScimGroupResolverTest extends DatabaseTestCase
{
    public function testItResolvesTheCurrentBoardDropToTheServingBoard(): void
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

        // The drop holds the members the register counts as on the board today: a released board
        // leaves it on its release date, not at the later discharge.
        $expected = array_values(array_map(
            static fn (BoardMember $boardMember): int => $boardMember->member->lidnr,
            array_filter(
                self::getContainer()->get(BoardMemberRepository::class)->findAll(),
                static fn (BoardMember $boardMember): bool => $boardMember->member->isCurrentBoard($boardMember),
            ),
        ));
        self::assertEqualsCanonicalizing(
            $expected,
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

    public function testItResolvesABoardFunctionDropToTheServingMember(): void
    {
        $group = self::getContainer()->get(ScimGroupResolver::class)
            ->resolve(
                ScimLocalTypes::Board,
                'function:Secretaris',
            );

        self::assertNotNull($group);
        self::assertSame(
            'BOARD_Secretary',
            $group->displayName,
        );
        self::assertSame(
            'board:secretary',
            $group->externalId,
        );
        // The current board's secretary, the second seat of the current term.
        self::assertSame(
            [MemberPopulationFixture::BOARD + 1],
            $group->memberLidnrs,
        );
    }

    public function testItResolvesTheReleasedButUndischargedDrop(): void
    {
        $group = self::getContainer()->get(ScimGroupResolver::class)
            ->resolve(
                ScimLocalTypes::Board,
                'function:Secretaris:plus',
            );

        self::assertNotNull($group);
        self::assertSame(
            'BOARD_SecretaryPlus',
            $group->displayName,
        );
        self::assertSame(
            'board:secretary:plus',
            $group->externalId,
        );
        // The seed's previous board is released but not discharged; its secretary, the second
        // seat of that term, sits in the Plus drop rather than the current one.
        self::assertSame(
            [8055],
            $group->memberLidnrs,
        );
    }

    public function testItResolvesTheKeyholdersStatusDropToTheCurrentGrants(): void
    {
        $group = self::getContainer()->get(ScimGroupResolver::class)
            ->resolve(
                ScimLocalTypes::Status,
                ScimGroupResolver::STATUS_KEYHOLDERS,
            );

        self::assertNotNull($group);
        self::assertSame(
            'KEYHOLDERS',
            $group->displayName,
        );
        self::assertSame(
            'STATUS:keyholders',
            $group->externalId,
        );

        // The seed grants one current key and withdraws the other; the group holds the member of
        // the grant that is still valid.
        $currentLidnrs = array_values(array_map(
            static fn (Keyholder $keyholder): int => $keyholder->member->lidnr,
            array_filter(
                self::getContainer()->get(KeyholderRepository::class)->findAll(),
                static fn (Keyholder $keyholder): bool => $keyholder->isCurrent(),
            ),
        ));
        self::assertEqualsCanonicalizing(
            $currentLidnrs,
            $group->memberLidnrs,
        );
    }

    public function testItResolvesABodyFunctionDropToTheCurrentOfficeholders(): void
    {
        $chair = null;
        foreach (self::getContainer()->get(OrganMemberRepository::class)->findAll() as $organMember) {
            if (
                InstallationFunctions::Chair === $organMember->function
                && $organMember->isCurrent()
            ) {
                $chair = $organMember;

                break;
            }
        }

        self::assertNotNull(
            $chair,
            'The seed must have a current chair.',
        );
        $hash = $chair->organ->foundation->getHash();
        $group = self::getContainer()->get(ScimGroupResolver::class)->resolve(
            ScimLocalTypes::BodyFunction,
            ScimGroupResolver::bodyFunctionKey(
                $hash,
                InstallationFunctions::Chair,
            ),
        );

        self::assertNotNull($group);
        self::assertSame(
            'BODY_' . $chair->organ->abbr . '_Chair',
            $group->displayName,
        );
        self::assertSame(
            'BODY:' . $hash . ':' . InstallationFunctions::Chair->value,
            $group->externalId,
        );
        self::assertContains(
            $chair->member->lidnr,
            $group->memberLidnrs,
        );
    }

    public function testTheInactiveFraternitySeatIsSeparateFromTheActiveBody(): void
    {
        $inactive = null;
        foreach (self::getContainer()->get(OrganMemberRepository::class)->findAll() as $organMember) {
            if (
                InstallationFunctions::InactiveMember === $organMember->function
                && $organMember->isCurrent()
            ) {
                $inactive = $organMember;

                break;
            }
        }

        self::assertNotNull(
            $inactive,
            'The seed must have a current inactive fraternity seat.',
        );
        $hash = $inactive->organ->foundation->getHash();
        $resolver = self::getContainer()->get(ScimGroupResolver::class);

        $active = $resolver->resolve(
            ScimLocalTypes::Body,
            $hash,
        );
        self::assertNotNull($active);
        self::assertNotContains(
            $inactive->member->lidnr,
            $active->memberLidnrs,
        );

        $inactiveGroup = $resolver->resolve(
            ScimLocalTypes::Body,
            $hash . ScimGroupResolver::BODY_INACTIVE_SUFFIX,
        );
        self::assertNotNull($inactiveGroup);
        self::assertSame(
            'BODY_' . $inactive->organ->abbr . '_Inactive',
            $inactiveGroup->displayName,
        );
        self::assertContains(
            $inactive->member->lidnr,
            $inactiveGroup->memberLidnrs,
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
