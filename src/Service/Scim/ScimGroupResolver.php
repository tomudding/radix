<?php

declare(strict_types=1);

namespace App\Service\Scim;

use App\Entity\Database\Enums\BoardFunctions;
use App\Entity\Database\Enums\MembershipTypes;
use App\Entity\Decision\AssociationYear;
use App\Entity\Decision\BoardMember;
use App\Entity\Decision\Organ;
use App\Entity\User\Enums\ScimLocalTypes;
use App\Repository\Decision\BoardMemberRepository;
use App\Repository\Decision\KeyholderRepository;
use App\Repository\Decision\MemberRepository;
use App\Repository\Decision\OrganMemberRepository;
use App\Repository\Decision\OrganRepository;
use DateTimeImmutable;

use function array_map;
use function array_unique;
use function array_values;
use function is_numeric;
use function range;
use function sprintf;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Turns a local group reference — a body's foundation hash, a board drop's key or a membership
 * state — into the display name, the external identifier and the member numbers.
 *
 * A body's membership is its current members while it is active and its members at the abrogation
 * date once abrogated; a board drop is either one association year's board, the board in office or
 * the last six boards together; a status group is the active members of one membership type, or
 * the formal classes together and the graduates. The mapping is read straight off the projection,
 * so whatever a dispatch or a reconciliation feeds it in, the handler that consumes it sees the
 * same group.
 */
final class ScimGroupResolver
{
    public const string BOARD_CURRENT = 'current';
    public const string BOARD_RECENT = 'recent';

    /**
     * The local id prefix of a board function drop; the Plus suffix names the released-but-
     * undischarged drop of the same function.
     */
    public const string BOARD_FUNCTION_PREFIX = 'function:';
    public const string BOARD_FUNCTION_PLUS_SUFFIX = ':plus';

    public const string STATUS_MEMBERS = 'members';
    public const string STATUS_GRADUATES = 'graduates';
    public const string STATUS_KEYHOLDERS = 'keyholders';

    public function __construct(
        private readonly OrganRepository $organRepository,
        private readonly OrganMemberRepository $organMemberRepository,
        private readonly BoardMemberRepository $boardMemberRepository,
        private readonly MemberRepository $memberRepository,
        private readonly KeyholderRepository $keyholderRepository,
    ) {
    }

    /**
     * Resolves any group reference, so a caller (a dispatch, a reconciliation) treats the group
     * kinds uniformly. Null when the reference names nothing the projection has.
     */
    public function resolve(
        ScimLocalTypes $localType,
        string $localId,
        ?DateTimeImmutable $on = null,
    ): ?ResolvedScimGroup {
        return match ($localType) {
            ScimLocalTypes::Body => $this->bodyGroup($localId),
            ScimLocalTypes::Board => $this->boardGroup(
                $localId,
                $on,
            ),
            ScimLocalTypes::Status => $this->statusGroup($localId),
            ScimLocalTypes::User => null,
        };
    }

    /**
     * Every group reference the projection has, for a full sync or a reconciliation: the bodies
     * (active and abrogated, by foundation hash), the board years that ever had a term plus the
     * two fixed drops, and the statuses. The reconciliation walks this so a group that a write
     * never touched is still brought in line.
     *
     * @return list<ResolvedScimGroupRef>
     */
    public function allGroupRefs(): array
    {
        $refs = [];
        foreach ($this->organIds() as $foundationHash) {
            $refs[] = new ResolvedScimGroupRef(
                ScimLocalTypes::Body,
                $foundationHash,
            );
        }

        $boardKeys = [
            self::BOARD_CURRENT,
            self::BOARD_RECENT,
        ];
        foreach ($this->boardMemberRepository->findInstallationYears() as $firstYear) {
            $boardKeys[] = (string) ($firstYear - ScimGroupKey::FOUNDING_FIRST_YEAR);
        }

        // Every board function, current and Plus: a function the board never used still gets both
        // drops, so a first installation does not wait for the next full sweep.
        foreach (BoardFunctions::cases() as $function) {
            $boardKeys[] = self::boardFunctionKey($function);
            $boardKeys[] = self::boardFunctionKey($function) . self::BOARD_FUNCTION_PLUS_SUFFIX;
        }

        foreach ($boardKeys as $boardKey) {
            $refs[] = new ResolvedScimGroupRef(
                ScimLocalTypes::Board,
                $boardKey,
            );
        }

        $statusIds = [
            self::STATUS_MEMBERS,
            self::STATUS_GRADUATES,
            self::STATUS_KEYHOLDERS,
        ];
        foreach (MembershipTypes::cases() as $type) {
            $statusIds[] = $type->value;
        }

        foreach ($statusIds as $statusId) {
            $refs[] = new ResolvedScimGroupRef(
                ScimLocalTypes::Status,
                $statusId,
            );
        }

        return $refs;
    }

    /**
     * The group references a member currently belongs to: their bodies, the board drops their
     * current installation appears in, and their status. This is what a user sync must resync
     * once the member's link exists, because those groups could not resolve the member before.
     *
     * @return list<ResolvedScimGroupRef>
     */
    public function refsForMember(int $lidnr): array
    {
        $refs = [];

        foreach ($this->organMemberRepository->findCurrentOrganIds($lidnr) as $organId) {
            $hash = $this->foundationHashOfOrgan($organId);
            if (null === $hash) {
                continue;
            }

            $refs[] = new ResolvedScimGroupRef(
                ScimLocalTypes::Body,
                $hash,
            );
        }

        $boardKeys = [
            self::BOARD_CURRENT,
            self::BOARD_RECENT,
        ];
        $boardYear = $this->boardYearOfMember($lidnr);
        if (null !== $boardYear) {
            $boardKeys[] = (string) $boardYear;
        }

        foreach ($boardKeys as $boardKey) {
            $refs[] = new ResolvedScimGroupRef(
                ScimLocalTypes::Board,
                $boardKey,
            );
        }

        $member = $this->memberRepository->find($lidnr);
        if (null !== $member) {
            $now = new DateTimeImmutable();
            foreach ($member->getBoardInstallations() as $installation) {
                $functionKey = self::boardFunctionKey($installation->function);
                if ($member->isCurrentBoard($installation)) {
                    $refs[] = new ResolvedScimGroupRef(
                        ScimLocalTypes::Board,
                        $functionKey,
                    );
                }

                if (
                    !$this->isReleasedButUndischarged(
                        $installation,
                        $now,
                    )
                ) {
                    continue;
                }

                $refs[] = new ResolvedScimGroupRef(
                    ScimLocalTypes::Board,
                    $functionKey . self::BOARD_FUNCTION_PLUS_SUFFIX,
                );
            }
        }

        $statusRefs = $this->statusRefsForMember($lidnr);
        foreach ($statusRefs as $statusRef) {
            $refs[] = $statusRef;
        }

        return $refs;
    }

    /**
     * Whether a board installation is in the released-but-not-yet-discharged transition — the
     * same rule {@see \App\Entity\Decision\Member::isReleasedButUndischargedSecretary()} applies,
     * generalized to any function.
     */
    private function isReleasedButUndischarged(
        BoardMember $installation,
        DateTimeImmutable $now,
    ): bool {
        $released = $installation->releaseDate;
        $discharged = $installation->dischargeDate;

        return null !== $released
            && $released <= $now
            && (
                null === $discharged
                || $discharged > $now
            );
    }

    private function bodyGroup(string $foundationHash): ?ResolvedScimGroup
    {
        $organ = $this->organRepository->findByFoundationHash($foundationHash);
        if (null === $organ) {
            return null;
        }

        $key = $this->bodyKey(
            $organ,
            $foundationHash,
        );

        $memberLidnrs = null === $organ->abrogationDate
            ? $this->organMemberRepository->findCurrentMemberLidnrs((int) $organ->id)
            : $this->organMemberRepository->findMemberLidnrsByDate(
                (int) $organ->id,
                $organ->abrogationDate,
            );

        return new ResolvedScimGroup(
            displayName: $key->displayName,
            externalId: $key->externalId,
            memberLidnrs: $memberLidnrs,
        );
    }

    private function boardGroup(
        string $boardKey,
        ?DateTimeImmutable $on,
    ): ?ResolvedScimGroup {
        if (null === $on) {
            $on = new DateTimeImmutable();
        }

        if (self::BOARD_CURRENT === $boardKey) {
            $key = ScimGroupKey::currentBoard();

            return new ResolvedScimGroup(
                displayName: $key->displayName,
                externalId: $key->externalId,
                memberLidnrs: $this->boardMemberRepository->findMembersByDate($on),
            );
        }

        if (self::BOARD_RECENT === $boardKey) {
            $key = ScimGroupKey::recentBoards();
            $memberLidnrs = [];
            foreach ($this->recentYears(AssociationYear::fromDate($on)->getYear()) as $firstYear) {
                $memberLidnrs = [
                    ...$memberLidnrs,
                    ...$this->boardMemberRepository->findMembersOfAssociationYear($firstYear),
                ];
            }

            return new ResolvedScimGroup(
                displayName: $key->displayName,
                externalId: $key->externalId,
                memberLidnrs: array_values(array_unique($memberLidnrs)),
            );
        }

        if (
            str_starts_with(
                $boardKey,
                self::BOARD_FUNCTION_PREFIX,
            )
        ) {
            return $this->boardFunctionGroup(
                $boardKey,
                $on,
            );
        }

        if (
            !is_numeric($boardKey)
            || (int) $boardKey < 1
        ) {
            return null;
        }

        $firstYear = (int) $boardKey + ScimGroupKey::FOUNDING_FIRST_YEAR;
        $key = ScimGroupKey::boardYear(AssociationYear::fromYear($firstYear));

        return new ResolvedScimGroup(
            displayName: $key->displayName,
            externalId: $key->externalId,
            memberLidnrs: $this->boardMemberRepository->findMembersOfAssociationYear($firstYear),
        );
    }

    private function boardFunctionGroup(
        string $boardKey,
        DateTimeImmutable $on,
    ): ?ResolvedScimGroup {
        $name = substr(
            $boardKey,
            strlen(self::BOARD_FUNCTION_PREFIX),
        );
        $plus = str_ends_with(
            $name,
            self::BOARD_FUNCTION_PLUS_SUFFIX,
        );
        if ($plus) {
            $name = substr(
                $name,
                0,
                -strlen(self::BOARD_FUNCTION_PLUS_SUFFIX),
            );
        }

        // The local id carries the function's Dutch value, which is what the decisions write.
        $function = BoardFunctions::tryFrom($name);
        if (null === $function) {
            return null;
        }

        $key = ScimGroupKey::boardFunction(
            $function,
            $plus,
        );

        return new ResolvedScimGroup(
            displayName: $key->displayName,
            externalId: $key->externalId,
            memberLidnrs: $plus
                ? $this->boardMemberRepository->findReleasedButUndischargedLidnrsByFunction(
                    $function,
                    $on,
                )
                : $this->boardMemberRepository->findCurrentLidnrsByFunction(
                    $function,
                    $on,
                ),
        );
    }

    /**
     * The local id of a board function drop. The function's Dutch value is the stable identity
     * the decisions carry; the display name and external id render the English case name.
     */
    public static function boardFunctionKey(BoardFunctions $function): string
    {
        return self::BOARD_FUNCTION_PREFIX . $function->value;
    }

    private function statusGroup(string $statusId): ?ResolvedScimGroup
    {
        if (self::STATUS_MEMBERS === $statusId) {
            $key = ScimGroupKey::formalMembers();
            $memberLidnrs = $this->activeFormalMembers();

            return new ResolvedScimGroup(
                displayName: $key->displayName,
                externalId: $key->externalId,
                memberLidnrs: $memberLidnrs,
            );
        }

        if (self::STATUS_GRADUATES === $statusId) {
            $key = ScimGroupKey::graduates();

            return new ResolvedScimGroup(
                displayName: $key->displayName,
                externalId: $key->externalId,
                memberLidnrs: $this->memberRepository->findActiveLidnrsByType(MembershipTypes::Graduate),
            );
        }

        if (self::STATUS_KEYHOLDERS === $statusId) {
            $key = ScimGroupKey::keyholders();

            return new ResolvedScimGroup(
                displayName: $key->displayName,
                externalId: $key->externalId,
                memberLidnrs: $this->keyholderRepository->findCurrentLidnrs(),
            );
        }

        $type = MembershipTypes::tryFrom($statusId);
        if (null === $type) {
            return null;
        }

        $key = ScimGroupKey::status($statusId);

        return new ResolvedScimGroup(
            displayName: $key->displayName,
            externalId: $key->externalId,
            memberLidnrs: $this->memberRepository->findActiveLidnrsByType($type),
        );
    }

    /**
     * The status groups a member belongs to: their own type's group plus, when the type is a
     * formal membership, the MEMBERS group; when it is a graduate, the GRADUATES group; and the
     * KEYHOLDERS group while their key grant is current.
     *
     * @return list<ResolvedScimGroupRef>
     */
    private function statusRefsForMember(int $lidnr): array
    {
        $member = $this->memberRepository->find($lidnr);
        if (null === $member) {
            return [];
        }

        $refs = [
            new ResolvedScimGroupRef(
                ScimLocalTypes::Status,
                $member->type->value,
            ),
        ];
        $refs[] = new ResolvedScimGroupRef(
            ScimLocalTypes::Status,
            $member->type->isFormalMember()
                ? self::STATUS_MEMBERS
                : self::STATUS_GRADUATES,
        );
        if ($member->isKeyholder()) {
            $refs[] = new ResolvedScimGroupRef(
                ScimLocalTypes::Status,
                self::STATUS_KEYHOLDERS,
            );
        }

        return $refs;
    }

    /**
     * The foundation hash of every body the projection holds, active and abrogated. This is the
     * complete set a reconciliation reconciles, because a body that was never written to after a
     * dispatch still has a group on the source.
     *
     * @return list<string>
     */
    private function organIds(): array
    {
        $rows = $this->organRepository->createQueryBuilder('o')
            ->select(
                'f.meeting_type AS meetingType',
                'f.meeting_number AS meetingNumber',
                'f.decision_point AS decisionPoint',
                'f.decision_number AS decisionNumber',
                'f.sequence AS sequence',
            )
            ->join(
                'o.foundation',
                'f',
            )
            ->getQuery()
            ->getScalarResult();

        return array_values(array_map(
            static fn (array $row): string => sprintf(
                '%s-%d.%d.%d.%d',
                (string) $row['meetingType'],
                (int) $row['meetingNumber'],
                (int) $row['decisionPoint'],
                (int) $row['decisionNumber'],
                (int) $row['sequence'],
            ),
            $rows,
        ));
    }

    /**
     * @return list<int> the lidnrs of the active ordinary, external and honorary members
     */
    private function activeFormalMembers(): array
    {
        $lidnrs = [];
        foreach (
            [
                MembershipTypes::Ordinary,
                MembershipTypes::External,
                MembershipTypes::Honorary,
            ] as $type
        ) {
            $lidnrs = [
                ...$lidnrs,
                ...$this->memberRepository->findActiveLidnrsByType($type),
            ];
        }

        return array_values(array_unique($lidnrs));
    }

    private function foundationHashOfOrgan(int $organId): ?string
    {
        $organ = $this->organRepository->find($organId);
        if (
            null === $organ
            || null === $organ->foundation
        ) {
            return null;
        }

        return $organ->foundation->getHash();
    }

    /**
     * The association-year index of the member's current board installation, or null when the
     * member is not on the board.
     */
    private function boardYearOfMember(int $lidnr): ?int
    {
        $member = $this->memberRepository->find($lidnr);
        if (null === $member) {
            return null;
        }

        $current = $member->getCurrentBoardInstallation();
        if (null === $current) {
            return null;
        }

        return AssociationYear::fromDate($current->installDate)->getYear() - ScimGroupKey::FOUNDING_FIRST_YEAR;
    }

    /**
     * @return list<int> the association years of the last six boards, oldest first
     */
    private function recentYears(int $currentFirstYear): array
    {
        return array_values(range(
            $currentFirstYear - 5,
            $currentFirstYear,
        ));
    }

    private function bodyKey(
        Organ $organ,
        string $foundationHash,
    ): ScimGroupKey {
        if (null === $organ->abrogationDate) {
            return ScimGroupKey::activeBody(
                $organ->abbr,
                $foundationHash,
            );
        }

        return ScimGroupKey::abrogatedBody(
            $organ->abbr,
            $foundationHash,
            (int) $organ->abrogationDate->format('Y'),
        );
    }
}
