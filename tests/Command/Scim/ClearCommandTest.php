<?php

declare(strict_types=1);

namespace App\Tests\Command\Scim;

use App\Command\Scim\ClearCommand;
use App\Service\Scim\ScimGroup;
use App\Service\Scim\ScimUser;
use App\Tests\MessageHandler\Scim\FakeScimClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:scim:clear` deletes exactly the objects the sync's own scheme manages: the m-number
 * accounts and the groups with a radix external id. Everything else on the source — its admin,
 * the service account, a group outside the scheme — survives, and a production environment is
 * refused outright.
 */
#[CoversClass(ClearCommand::class)]
final class ClearCommandTest extends TestCase
{
    public function testItDeletesOnlyTheSyncedObjects(): void
    {
        $client = new FakeScimClient();
        $client->listedUsers = [
            new ScimUser(
                userName: 'm8000',
                externalId: '8000',
                remoteId: 'user-1',
            ),
            new ScimUser(
                userName: 'akadmin',
                remoteId: 'user-2',
            ),
            new ScimUser(
                userName: 'ak-source-scim-abc',
                remoteId: 'user-3',
            ),
        ];
        $client->listedGroups = [
            new ScimGroup(
                displayName: 'BODY_X',
                externalId: 'BODY:ALV-1.1.1.1',
                remoteId: 'group-1',
            ),
            new ScimGroup(
                displayName: 'BOARD_Current',
                externalId: 'board:current',
                remoteId: 'group-2',
            ),
            new ScimGroup(
                displayName: 'KEYHOLDERS',
                externalId: 'STATUS:keyholders',
                remoteId: 'group-3',
            ),
            new ScimGroup(
                displayName: 'native',
                externalId: 'some-native-id',
                remoteId: 'group-4',
            ),
            new ScimGroup(
                displayName: 'no-external-id',
                remoteId: 'group-5',
            ),
        ];

        $tester = new CommandTester(
            new ClearCommand(
                $client,
                true,
                'dev',
            ),
        );
        $tester->execute([]);

        self::assertSame(
            [
                'user-1',
            ],
            $client->deletedUsers,
        );
        self::assertSame(
            [
                'group-1',
                'group-2',
                'group-3',
            ],
            $client->deletedGroups,
        );
        self::assertStringContainsString(
            'Deleted 1 users and 3 groups',
            $tester->getDisplay(),
        );
    }

    public function testItRefusesUnderProd(): void
    {
        $client = new FakeScimClient();
        $client->listedUsers = [
            new ScimUser(
                userName: 'm8000',
                remoteId: 'user-1',
            ),
        ];

        $tester = new CommandTester(
            new ClearCommand(
                $client,
                true,
                'prod',
            ),
        );
        $tester->execute([]);

        self::assertSame(
            1,
            $tester->getStatusCode(),
        );
        self::assertSame(
            [],
            $client->deletedUsers,
        );
    }

    public function testItIsANoOpWhileDisabled(): void
    {
        $client = new FakeScimClient();

        $tester = new CommandTester(
            new ClearCommand(
                $client,
                false,
                'dev',
            ),
        );
        $tester->execute([]);

        self::assertSame(
            0,
            $tester->getStatusCode(),
        );
        self::assertStringContainsString(
            'SCIM is disabled',
            $tester->getDisplay(),
        );
        self::assertSame(
            [],
            $client->deletedUsers,
        );
    }
}
