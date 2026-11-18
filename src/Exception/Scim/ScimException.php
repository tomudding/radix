<?php

declare(strict_types=1);

namespace App\Exception\Scim;

use RuntimeException;

use function sprintf;

/**
 * A request to the SCIM endpoint failed. The message names the endpoint, the status and the
 * SCIM error detail and nothing else: the bearer token and the payload must not reach the logs
 * through an exception message.
 */
class ScimException extends RuntimeException
{
    public function __construct(
        public readonly string $endpoint,
        public readonly ?int $status,
        public readonly bool $retryable,
        public readonly ?string $detail = null,
        public readonly ?int $retryAfterMs = null,
    ) {
        $message = sprintf(
            'SCIM request "%s" failed',
            $endpoint,
        );
        if (null !== $status) {
            $message .= sprintf(
                ' with status %d',
                $status,
            );
        }

        if (
            null !== $detail
            && '' !== $detail
        ) {
            $message .= sprintf(
                ': %s',
                $detail,
            );
        }

        parent::__construct($message);
    }
}
