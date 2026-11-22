<?php

declare(strict_types=1);

namespace App\Service\Scim;

use App\Entity\Database\Enums\OrganTypes;
use App\Entity\Decision\AssociationYear;

use function sprintf;

/**
 * The stable identity and the displayed name of a group on the SCIM source, derived from the
 * local facts rather than the projection's database id, which churns on every nightly rebuild.
 *
 * The names are fixed by the consumers the groups are provisioned for: an active organ is
 * `ORGAN_<abbr>`, a board year is `BOARD_<n>` with n counted from GEWIS's founding (1982-83 is
 * 1, so the 2025-26 board is 44), and the two computed groups are `BOARD_Current` — the board in
 * office right now — and `BOARD_Boards` — the current board plus the four before it.
 *
 * The externalId carries the same facts in a form made for correlation: a SCIM source correlates
 * groups on displayName and users on userName, and a stable externalId lets a later reconcile
 * tell which local object a remote one stands for without trusting either name.
 */
final readonly class ScimGroupKey
{
    private const int FOUNDING_FIRST_YEAR = 1981;

    public function __construct(
        public string $displayName,
        public string $externalId,
    ) {
    }

    public static function organ(
        OrganTypes $type,
        string $abbr,
    ): self {
        return new self(
            displayName: 'ORGAN_' . $abbr,
            externalId: sprintf(
                'organ:%s:%s',
                $type->value,
                $abbr,
            ),
        );
    }

    /**
     * The board group for one association year. The year is counted from GEWIS's founding, so
     * `BOARD_44` is the board of 2025-26.
     */
    public static function year(AssociationYear $year): self
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
     * The group that holds the boards of the last few years, current included. Which years count
     * and how their memberships overlap is the sync handler's decision; the name is fixed here.
     */
    public static function recentBoards(): self
    {
        return new self(
            displayName: 'BOARD_Boards',
            externalId: 'board:recent',
        );
    }
}
