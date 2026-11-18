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
 * The link between a local object (a user or an organ) and the object the SCIM source manages on
 * our behalf.
 *
 * A row exists only once the remote object is known: the remote identifier is assigned when the
 * object is created or adopted. The hash of the last synced payload lets a handler skip the call
 * when nothing changed, and {@see $lastError} records the most recent failure so the runbook has
 * something to start from.
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

    #[Column(type: Types::INTEGER)]
    public int $localId;

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
        int $localId,
        string $remoteId,
    ) {
        $this->localType = $localType;
        $this->localId = $localId;
        $this->remoteId = $remoteId;
    }
}
