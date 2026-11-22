<?php

declare(strict_types=1);

namespace App\Tests\Service\Scim;

use App\Service\Scim\UserScimMapper;
use App\Service\Scim\UserScimSource;
use PHPUnit\Framework\TestCase;

/**
 * The mapper is where the conventions live — the `m` prefix that keeps pushed names clear of the
 * Authentik's own users, the member number as externalId, the single work email as the primary —
 * so every one of them is pinned here rather than assumed by the tests that use the mapper.
 */
final class UserScimMapperTest extends TestCase
{
    public function testMapsTheMemberToTheScimUser(): void
    {
        $mapped = new UserScimMapper()->map(new UserScimSource(
            lidnr: '8000',
            firstName: 'Jan',
            lastName: 'de Vries',
            email: 'jan.de.vries@example.org',
            active: true,
        ));

        self::assertSame(
            'm8000',
            $mapped->userName,
        );
        self::assertSame(
            '8000',
            $mapped->externalId,
        );
        self::assertSame(
            'Jan',
            $mapped->givenName,
        );
        self::assertSame(
            'de Vries',
            $mapped->familyName,
        );
        self::assertSame(
            'Jan de Vries',
            $mapped->formattedName,
        );
        self::assertTrue($mapped->active);
        self::assertNull($mapped->remoteId);
        self::assertCount(
            1,
            $mapped->emails,
        );
        self::assertSame(
            'jan.de.vries@example.org',
            $mapped->emails[0]->value,
        );
        self::assertTrue($mapped->emails[0]->primary);
        self::assertSame(
            'work',
            $mapped->emails[0]->type,
        );
    }

    public function testAMemberWithoutAnEmailYieldsNoEmails(): void
    {
        $mapped = new UserScimMapper()->map(new UserScimSource('8000', 'Jan', 'de Vries'));

        self::assertSame(
            [],
            $mapped->emails,
        );
    }

    public function testAnEmptyEmailIsTreatedAsMissing(): void
    {
        $mapped = new UserScimMapper()->map(new UserScimSource('8000', 'Jan', 'de Vries', ''));

        self::assertSame(
            [],
            $mapped->emails,
        );
    }

    public function testAnInactiveMemberIsPushedInactive(): void
    {
        $mapped = new UserScimMapper()->map(new UserScimSource('8000', 'Jan', 'de Vries', active: false));

        self::assertFalse($mapped->active);
    }

    public function testUnicodeNamesAreKeptByteExact(): void
    {
        $mapped = new UserScimMapper()->map(new UserScimSource('90040', 'Zoë', 'Müller-Schäfer 田中太郎'));

        self::assertSame(
            'Zoë',
            $mapped->givenName,
        );
        self::assertSame(
            'Müller-Schäfer 田中太郎',
            $mapped->familyName,
        );
        self::assertSame(
            'Zoë Müller-Schäfer 田中太郎',
            $mapped->formattedName,
        );
    }

    public function testAnEmptyFirstNameLeavesAUsableFormattedName(): void
    {
        $mapped = new UserScimMapper()->map(new UserScimSource('8000', '', 'de Vries'));

        self::assertSame(
            'de Vries',
            $mapped->formattedName,
        );
    }
}
