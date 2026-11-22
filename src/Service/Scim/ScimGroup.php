<?php

declare(strict_types=1);

namespace App\Service\Scim;

use function array_map;
use function hash;
use function json_encode;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * A group as the SCIM client speaks it. Members are the SCIM source's own user ids: a local id
 * must never reach the wire, which is why the caller resolves them through the link table
 * before this is built.
 */
final readonly class ScimGroup
{
    public const string SCHEMA = 'urn:ietf:params:scim:schemas:core:2.0:Group';

    /**
     * @param list<string> $memberRemoteIds
     */
    public function __construct(
        public string $displayName,
        public ?string $externalId = null,
        public array $memberRemoteIds = [],
        public ?string $remoteId = null,
    ) {
    }

    /**
     * The shape a member takes in the group payload and in an add/remove operation.
     *
     * @param list<string> $remoteIds
     *
     * @return list<array{value: string}>
     */
    public static function memberObjects(array $remoteIds): array
    {
        return array_map(
            static fn (string $remoteId): array => ['value' => $remoteId],
            $remoteIds,
        );
    }

    /**
     * The full payload as it goes on the wire.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'schemas' => [self::SCHEMA],
            'displayName' => $this->displayName,
            'members' => self::memberObjects($this->memberRemoteIds),
        ];
        if (null !== $this->externalId) {
            $payload['externalId'] = $this->externalId;
        }

        return $payload;
    }

    /**
     * A hash of the wire payload, so a handler can skip a sync that would change nothing.
     */
    public function payloadHash(): string
    {
        return hash(
            'sha256',
            (string) json_encode(
                $this->toArray(),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ),
        );
    }
}
