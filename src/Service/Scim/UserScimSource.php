<?php

declare(strict_types=1);

namespace App\Service\Scim;

use App\Entity\Decision\Member;

/**
 * The local facts a SCIM user is built from. A plain data holder on purpose: the ledger's
 * member entity stays out of the mapper, which keeps it a pure function.
 */
final readonly class UserScimSource
{
    public function __construct(
        public string $lidnr,
        public string $firstName,
        public string $lastName,
        public ?string $email = null,
        public bool $active = true,
    ) {
    }

    /**
     * The projection's member as a SCIM user. A lapsed membership, including a graduate's, and a
     * deleted member are inactive. Hidden is the register's own lock and makes the account
     * inactive as well.
     */
    public static function fromMember(Member $member): self
    {
        return new self(
            lidnr: (string) $member->lidnr,
            firstName: $member->firstName,
            lastName: $member->lastName,
            email: $member->email,
            active: !$member->deleted
                && !$member->hidden
                && !$member->isExpired(),
        );
    }
}
