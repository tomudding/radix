<?php

declare(strict_types=1);

namespace App\Service\Scim;

use App\Entity\Database\Enums\BoardFunctions;
use App\Entity\Decision\AssociationYear;

use function sprintf;
use function strtolower;
use function ucfirst;

/**
 * The stable identity and the displayed name of a group on the SCIM source.
 *
 * The externalId always names the body by its foundation hash — never by its abbreviation, which
 * a later body can reuse after an abrogation — and does not change when the body is abrogated;
 * only the displayName does, gaining the HIST_ marker and the year. The boards are counted from
 * GEWIS's founding (1982-83 is 1, so the 2025-26 board is 44), plus the fixed BOARD_Current and
 * BOARD_Boards. These names are fixed by the consumers, so each is pinned here.
 */
final readonly class ScimGroupKey
{
    public const int FOUNDING_FIRST_YEAR = 1981;

    private function __construct(
        public string $displayName,
        public string $externalId,
    ) {
    }

    /**
     * An active body. The clean name belongs to the one body that bears the letters right now.
     */
    public static function activeBody(
        string $abbr,
        string $foundationHash,
    ): self {
        return new self(
            displayName: 'BODY_' . $abbr,
            externalId: 'BODY:' . $foundationHash,
        );
    }

    /**
     * An abrogated body. The HIST_ marker and the abrogation year keep it apart from the body that
     * later took the same letters; the externalId stays the foundation hash, unchanged from when the
     * body was active.
     *
     * @param int $abrogationYear the calendar year the abrogation took effect
     */
    public static function abrogatedBody(
        string $abbr,
        string $foundationHash,
        int $abrogationYear,
    ): self {
        return new self(
            displayName: sprintf(
                'HIST_BODY_%s (%d)',
                $abbr,
                $abrogationYear,
            ),
            externalId: 'BODY:' . $foundationHash,
        );
    }

    /**
     * The board group for one association year. The year is counted from GEWIS's founding, so
     * `BOARD_44` is the board of 2025-26.
     */
    public static function boardYear(AssociationYear $year): self
    {
        $number = $year->getYear() - self::FOUNDING_FIRST_YEAR;

        return new self(
            displayName: 'BOARD_' . $number,
            externalId: 'board:' . $number,
        );
    }

    /**
     * The group that holds the board in office. Its membership is recomputed from the board
     * installations around the association-year boundary (July 1st), not from a stored flag.
     */
    public static function currentBoard(): self
    {
        return new self(
            displayName: 'BOARD_Current',
            externalId: 'board:current',
        );
    }

    /**
     * The group that holds the current board and the five before it — per the consumers, the
     * "last five boards" drop is those six years together. The board that left on July 1st is
     * therefore still present here the day after, while BOARD_Current has already moved on to the
     * new one.
     */
    public static function recentBoards(): self
    {
        return new self(
            displayName: 'BOARD_Boards',
            externalId: 'board:recent',
        );
    }

    /**
     * The group for one board function: the members installed in it and not yet discharged. The
     * Plus drop holds those released from the function but not yet discharged — the transition
     * in which a secretary or treasurer keeps their register rights. The function is named by its
     * English case name, so the drops read BOARD_Secretary and BOARD_SecretaryPlus.
     */
    public static function boardFunction(
        BoardFunctions $function,
        bool $plus = false,
    ): self {
        $name = $function->name;

        return new self(
            displayName: $plus
                ? 'BOARD_' . $name . 'Plus'
                : 'BOARD_' . $name,
            externalId: $plus
                ? 'board:' . strtolower($name) . ':plus'
                : 'board:' . strtolower($name),
        );
    }

    /**
     * The group for one membership type. Active members of that type only: a graduate is not a
     * member within the meaning of the law, but the type is still tracked, so the class is named
     * for the type.
     */
    public static function status(
        string $type,
    ): self {
        return new self(
            displayName: 'MEMBER_' . ucfirst($type),
            externalId: 'MEMBER:' . $type,
        );
    }

    /**
     * The group of the formal members together — the ordinary, external and honorary members, the
     * classes that may vote at a general meeting. Kept apart from the per-class groups above, and
     * from GRADUATES: a graduate holds graduate status, not membership.
     */
    public static function formalMembers(): self
    {
        return new self(
            displayName: 'MEMBERS',
            externalId: 'STATUS:members',
        );
    }

    /**
     * The group of the members with graduate status. Called graduates rather than a MEMBER_ class
     * because graduates are not members within the meaning of the law.
     */
    public static function graduates(): self
    {
        return new self(
            displayName: 'GRADUATES',
            externalId: 'STATUS:graduate',
        );
    }

    /**
     * The group of the members whose key grant is currently valid. Keyholdership is a grant with
     * an expiration, not a membership state, so it is its own drop rather than a MEMBER_ class.
     */
    public static function keyholders(): self
    {
        return new self(
            displayName: 'KEYHOLDERS',
            externalId: 'STATUS:keyholders',
        );
    }
}
