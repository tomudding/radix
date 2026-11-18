<?php

declare(strict_types=1);

namespace App\Tests\Service\Scim;

use App\Exception\Scim\ScimException;
use App\Message\Scim\SyncUserMessage;
use App\Service\Scim\ScimRetryStrategy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

/**
 * @covers \App\Service\Scim\ScimRetryStrategy
 */
final class ScimRetryStrategyTest extends TestCase
{
    public function testItDoesNotRetryAnythingThatIsNotAScimException(): void
    {
        self::assertFalse(
            new ScimRetryStrategy()->isRetryable(
                self::envelope(),
                new RuntimeException('no'),
            ),
        );
        self::assertFalse(
            new ScimRetryStrategy()->isRetryable(self::envelope()),
        );
    }

    public function testItDoesNotRetryAStatusTheClientClassifiedAsPermanent(): void
    {
        $exception = new ScimException(
            'PUT /Users/1',
            401,
            false,
            'Invalid token',
        );

        self::assertFalse(new ScimRetryStrategy()->isRetryable(self::envelope(), $exception));
    }

    public function testItRetriesAStatusTheClientClassifiedAsTransient(): void
    {
        $exception = new ScimException(
            'GET /Users',
            503,
            true,
        );

        self::assertTrue(new ScimRetryStrategy()->isRetryable(self::envelope(), $exception));
    }

    public function testItRetriesWhenTheWorkerWrapsTheException(): void
    {
        // The messenger worker wraps the handler's exception in a HandlerFailedException before the
        // retry listener sees it, so the strategy must classify the wrapped ScimException.
        $envelope = self::envelope();
        $strategy = new ScimRetryStrategy();

        $transient = new HandlerFailedException(
            $envelope,
            [
                new ScimException(
                    'POST /Users',
                    null,
                    true,
                    'could not connect',
                ),
            ],
        );
        self::assertTrue($strategy->isRetryable($envelope, $transient));

        $permanent = new HandlerFailedException(
            $envelope,
            [
                new ScimException(
                    'PUT /Users/1',
                    401,
                    false,
                    'Invalid token',
                ),
            ],
        );
        self::assertFalse($strategy->isRetryable($envelope, $permanent));

        $unrelated = new HandlerFailedException(
            $envelope,
            [new RuntimeException('no')],
        );
        self::assertFalse($strategy->isRetryable($envelope, $unrelated));
    }

    public function testItHonoursRetryAfterWrappedByTheWorker(): void
    {
        $exception = new HandlerFailedException(
            self::envelope(2),
            [
                new ScimException(
                    'GET /Users',
                    429,
                    true,
                    'Slow down',
                    15_000,
                ),
            ],
        );

        self::assertSame(
            15_000,
            new ScimRetryStrategy()->getWaitingTime(
                self::envelope(2),
                $exception,
            ),
        );
    }

    public function testItCapsAServerSuggestedRetryAfterAtTheMaximumWait(): void
    {
        $exception = new HandlerFailedException(
            self::envelope(4),
            [
                new ScimException(
                    'GET /Users',
                    429,
                    true,
                    'Slow down',
                    3_600_000_000,
                ),
            ],
        );

        self::assertSame(
            600_000,
            new ScimRetryStrategy()->getWaitingTime(
                self::envelope(4),
                $exception,
            ),
        );
    }

    public function testItStopsAfterTheBoundedNumberOfTrials(): void
    {
        $strategy = new ScimRetryStrategy();
        $exception = new ScimException(
            'GET /Users',
            503,
            true,
        );

        self::assertTrue($strategy->isRetryable(self::envelope(4), $exception));
        self::assertFalse($strategy->isRetryable(self::envelope(5), $exception));
    }

    public function testItHonoursAServerSuggestedRetryAfter(): void
    {
        $exception = new ScimException(
            'GET /Users',
            429,
            true,
            'Slow down',
            15_000,
        );

        self::assertSame(
            15_000,
            new ScimRetryStrategy()->getWaitingTime(
                self::envelope(2),
                $exception,
            ),
        );
    }

    #[DataProvider('trialProvider')]
    public function testItDoublesTheWaitPerTrialUpToTheCap(
        int $trials,
        int $expected,
    ): void {
        $exception = new ScimException(
            'GET /Users',
            503,
            true,
        );

        self::assertSame(
            $expected,
            new ScimRetryStrategy()->getWaitingTime(
                self::envelope($trials),
                $exception,
            ),
        );
    }

    /**
     * @return iterable<array{int, int}>
     */
    public static function trialProvider(): iterable
    {
        yield 'first trial' => [
            0,
            30_000,
        ];

        yield 'second trial' => [
            1,
            60_000,
        ];

        yield 'third trial' => [
            2,
            120_000,
        ];

        yield 'fifth trial' => [
            4,
            480_000,
        ];

        yield 'capped' => [
            8,
            600_000,
        ];
    }

    private function envelope(int $trials = 0): Envelope
    {
        $message = new SyncUserMessage(8000);

        // The transport re-sends a failed message with one RedeliveryStamp carrying how many
        // retries went before it.
        return $trials > 0
            ? new Envelope(
                $message,
                [new RedeliveryStamp($trials)],
            )
            : new Envelope($message);
    }
}
