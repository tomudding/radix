<?php

declare(strict_types=1);

namespace App\Entity\User;

use App\Entity\Application\Traits\IdentifiableTrait;
use App\Entity\User\Enums\ScimLocalTypes;
use App\Repository\User\ScimRemoteLinkRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\UniqueConstraint;

/**
 * The link between a local object (an account, a body or a board drop) and the object the SCIM
 * source manages on our behalf.
 *
 * A row exists only once the remote object is known: the remote identifier is assigned when the
 * object is created or adopted. The hash of the last synced payload lets a handler skip the call
 * when nothing changed, and {@see $lastError} records the most recent failure so the runbook has
 * something to start from.
 *
 * The local id is a string because the local objects have no common numeric id: an account is a
 * lidnr, a body is its foundation hash and a board drop is a fixed key (BOARD_Current,
 * BOARD_Boards or the year index). The local type tells which of the three the id names.
 */
#[Entity(repositoryClass: ScimRemoteLinkRepository::class)]
#[UniqueConstraint(
    name: 'scim_remote_link_local_uniq',
    columns: [
        'localType',
        'localId',
    ],
)]
class ScimRemoteLink
{
    use IdentifiableTrait;

    #[Column(
        type: Types::STRING,
        enumType: ScimLocalTypes::class,
    )]
    public ScimLocalTypes $localType;

    #[Column(
        type: Types::STRING,
        length: 255,
    )]
    public string $localId;

    #[Column(type: Types::STRING)]
    public string $remoteId;

    #[Column(
        type: Types::STRING,
        nullable: true,
    )]
    public ?string $payloadHash = null;

    #[Column(
        type: Types::DATETIME_IMMUTABLE,
        nullable: true,
    )]
    public ?DateTimeImmutable $lastSyncedAt = null;

    #[Column(
        type: Types::TEXT,
        nullable: true,
    )]
    public ?string $lastError = null;

    public function __construct(
        ScimLocalTypes $localType,
        string $localId,
        string $remoteId,
    ) {
        $this->localType = $localType;
        $this->localId = $localId;
        $this->remoteId = $remoteId;
    }
}
