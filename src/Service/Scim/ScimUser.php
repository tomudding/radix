<?php

declare(strict_types=1);

namespace App\Service\Scim;

use function array_filter;
use function array_map;
use function hash;
use function json_encode;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * A user as the SCIM client speaks it. The mapper fills everything except {@see $remoteId},
 * which the SCIM source assigns on create and the client carries on every response.
 */
final readonly class ScimUser
{
    public const string SCHEMA = 'urn:ietf:params:scim:schemas:core:2.0:User';

    /**
     * @param list<ScimEmail> $emails
     */
    public function __construct(
        public string $userName,
        public ?string $externalId = null,
        public ?string $givenName = null,
        public ?string $familyName = null,
        public ?string $formattedName = null,
        public array $emails = [],
        public bool $active = true,
        public ?string $remoteId = null,
    ) {
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
            'userName' => $this->userName,
            'name' => [
                'formatted' => $this->formattedName,
                'givenName' => $this->givenName,
                'familyName' => $this->familyName,
            ],
            'emails' => array_map(
                static fn (ScimEmail $email): array => array_filter(
                    [
                        'value' => $email->value,
                        'type' => $email->type,
                        'primary' => $email->primary,
                    ],
                    static fn (mixed $attribute): bool => null !== $attribute,
                ),
                $this->emails,
            ),
            'active' => $this->active,
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
