<?php

declare(strict_types=1);

namespace App\Service\Scim;

use App\Exception\Scim\ScimException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Retry\RetryStrategyInterface;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Throwable;

use function array_filter;
use function array_values;
use function min;

/**
 * The retry policy for the `scim` transport. What is transient is classified on the client's
 * {@see ScimException} (network failures, 429 with `Retry-After`, 5xx). The wait honours
 * `Retry-After` and otherwise doubles, capped, because the source rate-limits; the trials are
 * bounded, because a source that stays down is found by the reconciliation.
 */
final class ScimRetryStrategy implements RetryStrategyInterface
{
    private const int BASE_WAIT_MS = 30_000;
    private const int MAX_WAIT_MS = 600_000;
    private const int MAX_TRIALS = 5;

    public function isRetryable(
        Envelope $message,
        ?Throwable $throwable = null,
    ): bool {
        foreach ($this->scimExceptions($throwable) as $exception) {
            if ($exception->retryable) {
                return $this->trialNumber($message) < self::MAX_TRIALS;
            }
        }

        return false;
    }

    public function getWaitingTime(
        Envelope $message,
        ?Throwable $throwable = null,
    ): int {
        foreach ($this->scimExceptions($throwable) as $exception) {
            if (null !== $exception->retryAfterMs) {
                // Capped like the exponential branch: a far-future Retry-After would park the
                // message past the retry bound, where the failure transport never sees it.
                return (int) min(
                    self::MAX_WAIT_MS,
                    $exception->retryAfterMs,
                );
            }
        }

        return (int) min(
            self::MAX_WAIT_MS,
            self::BASE_WAIT_MS * 2 ** $this->trialNumber($message),
        );
    }

    /**
     * The worker wraps the handler's exception in a {@see HandlerFailedException} before the retry
     * listener sees it, so the {@see ScimException} the client threw is one level down.
     *
     * @return list<ScimException>
     */
    private function scimExceptions(?Throwable $throwable): array
    {
        if (null === $throwable) {
            return [];
        }

        if ($throwable instanceof HandlerFailedException) {
            return array_values(
                array_filter(
                    $throwable->getWrappedExceptions(),
                    static fn (Throwable $exception): bool => $exception instanceof ScimException,
                ),
            );
        }

        return $throwable instanceof ScimException
            ? [$throwable]
            : [];
    }

    private function trialNumber(Envelope $message): int
    {
        // The retry listener re-sends a failed message to the transport with a RedeliveryStamp
        // that carries how many retries went before it, so the count is the trial about to
        // start. A stamp added to the failed envelope itself would not survive the round trip.
        return RedeliveryStamp::getRetryCountFromEnvelope($message);
    }
}
