<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command\Scim;

use App\Command\Scim\ReconcileCommand;
use App\Entity\User\Enums\ScimLocalTypes;
use App\Entity\User\ScimRemoteLink;
use App\Entity\User\User;
use App\Message\Scim\SyncGroupMembershipMessage;
use App\Message\Scim\SyncGroupMessage;
use App\Message\Scim\SyncUserMessage;
use App\Repository\User\ScimRemoteLinkRepository;
use App\Repository\User\UserRepository;
use App\Service\Scim\GroupScimMapper;
use App\Service\Scim\GroupScimSource;
use App\Service\Scim\ResolvedScimGroupRef;
use App\Service\Scim\ScimEmail;
use App\Service\Scim\ScimGroup;
use App\Service\Scim\ScimGroupResolver;
use App\Service\Scim\ScimUser;
use App\Service\Scim\UserScimMapper;
use App\Service\Scim\UserScimSource;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\MessageHandler\Scim\FakeScimClient;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

use function array_filter;
use function array_map;
use function array_values;
use function in_array;
use function sprintf;

/**
 * `app:scim:reconcile` diffs the local state against what the SCIM source reports for its own
 * lists, and dispatches the ordinary sync messages that repair the drift.
 *
 * The source is the fake client, so every remote state in these tests is exactly the one the test
 * puts in its lists; the bus is a stub that records what the command would have dispatched.
 */
#[CoversClass(ReconcileCommand::class)]
final class ReconcileCommandTest extends DatabaseTestCase
{
    private FakeScimClient $client;
    private LoggerInterface $logger;
    private MessageBusInterface $bus;
    private ReconcileCommand $command;
    private ScimGroupResolver $groupResolver;

    /** @var list<object> */
    private array $dispatched = [];

    private string $display = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = new FakeScimClient();
        $this->logger = self::createStub(LoggerInterface::class);
        $this->dispatched = [];
        $this->display = '';
        $this->groupResolver = self::getContainer()->get(ScimGroupResolver::class);

        $this->bus = self::createStub(MessageBusInterface::class);
        $this->bus->method('dispatch')
            ->willReturnCallback(function (object $message, array $context = []): Envelope {
                $this->dispatched[] = $message;

                return new Envelope($message);
            });

        $this->command = $this->makeCommand();
    }

    public function testAnInSyncSourceDispatchesNothing(): void
    {
        $this->linkEverything();
        $this->sourceMirrorsLocal();

        $this->invoke();

        self::assertSame(
            [],
            $this->dispatched,
        );
        self::assertStringContainsString(
            'Dispatched 0 users, 0 groups and 0 membership syncs.',
            $this->display,
        );
    }

    public function testAMemberWithoutALinkIsDispatched(): void
    {
        $lidnr = $this->localUsers()[0]->member->lidnr;

        $this->linkEverything(skipUsers: [$lidnr]);
        $this->sourceMirrorsLocal(excludeUsers: [$lidnr]);

        // Users only, so no status or board group re-triggers a membership sync for the member.
        $this->invoke(['--type' => ['user']]);

        self::assertSame(
            [
                [
                    'user',
                    $lidnr,
                ],
            ],
            $this->sequence(),
        );
    }

    public function testAStalePayloadIsDispatched(): void
    {
        $member = $this->localUsers()[0]->member;
        $lidnr = $member->lidnr;

        $this->linkEverything();
        $this->sourceMirrorsLocal();

        $member->firstName = 'Renamed';
        $this->entityManager->flush();

        $this->invoke();

        self::assertSame(
            [
                [
                    'user',
                    $lidnr,
                ],
            ],
            $this->sequence(),
        );
        self::assertStringContainsString(
            sprintf(
                'member %d: the payload differs from what was last synced',
                $lidnr,
            ),
            $this->display,
        );
    }

    public function testASourceChangedUserIsDispatched(): void
    {
        $lidnr = $this->localUsers()[0]->member->lidnr;

        $this->linkEverything();
        $this->sourceMirrorsLocal();

        // The e-mail on the source side changed without a local write: the hash matches and the
        // object exists, so only the content check sees the drift.
        foreach ($this->client->listedUsers as $i => $listed) {
            if ('user-remote-' . $lidnr === $listed->remoteId) {
                $this->client->listedUsers[$i] = new ScimUser(
                    userName: $listed->userName,
                    externalId: $listed->externalId,
                    emails: [
                        new ScimEmail(
                            'changed@example.org',
                            primary: true,
                            type: 'work',
                        ),
                    ],
                    active: $listed->active,
                    remoteId: $listed->remoteId,
                );

                break;
            }
        }

        $this->invoke();

        self::assertSame(
            [
                [
                    'user',
                    $lidnr,
                ],
            ],
            $this->sequence(),
        );
        self::assertStringContainsString(
            sprintf(
                'member %d: the source changed what was last synced',
                $lidnr,
            ),
            $this->display,
        );
    }

    public function testASourceChangedGroupIsDispatched(): void
    {
        $ref = $this->aBodyGroupRef();
        $key = $this->groupKey($ref);

        $this->linkEverything();
        $this->sourceMirrorsLocal();

        // A member added on the source side without a local write: the membership set differs,
        // the hash does not, so only the content check sees the drift.
        $foreign = 'user-remote-999999';
        foreach ($this->client->listedGroups as $i => $listed) {
            if ('group-remote-' . $key === $listed->remoteId) {
                $this->client->listedGroups[$i] = new ScimGroup(
                    displayName: $listed->displayName,
                    externalId: $listed->externalId,
                    memberRemoteIds: [
                        ...$listed->memberRemoteIds,
                        $foreign,
                    ],
                    remoteId: $listed->remoteId,
                );

                break;
            }
        }

        $this->invoke();

        self::assertSame(
            [
                [
                    'group',
                    $key,
                ],
                [
                    'membership',
                    $key,
                ],
            ],
            $this->sequence(),
        );
        self::assertStringContainsString(
            sprintf(
                '%s: the source changed what was last synced',
                $ref->localId,
            ),
            $this->display,
        );
    }

    public function testAVanishedRemoteUserIsDispatchedAndTheLinkIsDropped(): void
    {
        $lidnr = $this->localUsers()[0]->member->lidnr;

        $this->linkEverything();
        $this->sourceMirrorsLocal(excludeUsers: [$lidnr]);

        $this->invoke(['--type' => ['user']]);

        self::assertSame(
            [
                [
                    'user',
                    $lidnr,
                ],
            ],
            $this->sequence(),
        );
        self::assertStringContainsString(
            sprintf(
                'member %d: the remote object is gone from the source',
                $lidnr,
            ),
            $this->display,
        );

        // The stale link is dropped, so the dispatched sync takes the create path instead of
        // skipping an unchanged hash.
        $this->entityManager->clear();
        self::assertNull(
            self::getContainer()->get(ScimRemoteLinkRepository::class)->findForUser($lidnr),
        );
    }

    public function testADryRunKeepsTheStaleLink(): void
    {
        $lidnr = $this->localUsers()[0]->member->lidnr;

        $this->linkEverything();
        $this->sourceMirrorsLocal(excludeUsers: [$lidnr]);

        $this->invoke([
            '--dry-run' => true,
            '--type' => ['user'],
        ]);

        self::assertSame(
            [],
            $this->dispatched,
        );
        self::assertStringContainsString(
            'Dry run; would drop 1 stale link',
            $this->display,
        );
        self::assertNotNull(
            self::getContainer()->get(ScimRemoteLinkRepository::class)->findForUser($lidnr),
        );
    }

    public function testOrphansAreReportedAndLeftAlone(): void
    {
        $logger = self::createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning');
        $this->command = $this->makeCommand($logger);

        $this->linkEverything();
        $this->sourceMirrorsLocal();
        $this->client->listedUsers[] = new ScimUser(
            userName: 'm99999',
            remoteId: 'orphan-1',
        );
        $this->client->listedUsers[] = new ScimUser(
            userName: 'akadmin',
            remoteId: 'native-1',
        );

        $this->invoke();

        self::assertSame(
            [],
            $this->dispatched,
        );
        self::assertStringContainsString(
            '1: m99999',
            $this->display,
        );
        self::assertStringNotContainsString(
            'akadmin',
            $this->display,
        );
    }

    public function testAGroupWithoutALinkDispatchesGroupAndMembership(): void
    {
        $key = $this->groupKey($this->aBodyGroupRef());

        $this->linkEverything(skipGroupKeys: [$key]);
        $this->sourceMirrorsLocal(excludeGroupKeys: [$key]);

        $this->invoke();

        self::assertSame(
            [
                [
                    'group',
                    $key,
                ],
                [
                    'membership',
                    $key,
                ],
            ],
            $this->sequence(),
        );
    }

    public function testAFullSyncDispatchesEveryoneWithoutListing(): void
    {
        $this->invoke(['--full' => true]);

        $expected = [];
        foreach ($this->localUsers() as $user) {
            $expected[] = [
                'user',
                (int) $user->member->lidnr,
            ];
        }

        foreach ($this->groupResolver->allGroupRefs() as $ref) {
            $key = $this->groupKey($ref);
            $expected[] = [
                'group',
                $key,
            ];
            $expected[] = [
                'membership',
                $key,
            ];
        }

        self::assertSame(
            $expected,
            $this->sequence(),
        );
        self::assertNotContains(
            'listUsers',
            $this->client->calls,
        );
        self::assertNotContains(
            'listGroups',
            $this->client->calls,
        );
    }

    public function testTheTypeOptionLimitsTheRun(): void
    {
        $lidnr = $this->localUsers()[0]->member->lidnr;
        $key = $this->groupKey($this->aBodyGroupRef());

        $this->linkEverything(
            skipUsers: [$lidnr],
            skipGroupKeys: [$key],
        );
        $this->sourceMirrorsLocal(
            excludeUsers: [$lidnr],
            excludeGroupKeys: [$key],
        );

        $this->invoke(['--type' => ['user']]);

        self::assertSame(
            [
                [
                    'user',
                    $lidnr,
                ],
            ],
            $this->sequence(),
        );
    }

    public function testADisabledScimRefusesToRun(): void
    {
        $this->command = $this->makeCommand(
            scimEnabled: false,
        );

        $this->invoke();

        self::assertSame(
            [],
            $this->dispatched,
        );
        self::assertStringContainsString(
            'SCIM is disabled',
            $this->display,
        );
        self::assertSame(
            [],
            $this->client->calls,
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    private function invoke(array $input = []): void
    {
        $tester = new CommandTester($this->command);
        $tester->execute($input);
        $this->display = $tester->getDisplay();
        self::assertSame(
            0,
            $tester->getStatusCode(),
            $this->display,
        );
    }

    private function makeCommand(
        ?LoggerInterface $logger = null,
        bool $scimEnabled = true,
    ): ReconcileCommand {
        $container = self::getContainer();
        $command = new ReconcileCommand(
            $this->client,
            $container->get(UserScimMapper::class),
            $container->get(GroupScimMapper::class),
            $container->get(UserRepository::class),
            $container->get(ScimRemoteLinkRepository::class),
            $container->get(ScimGroupResolver::class),
            $this->bus,
            $logger ?? $this->logger,
            $scimEnabled,
        );
        $command->setRunLockFactory(
            $container->get('lock.factory'),
        );

        return $command;
    }

    /**
     * The dispatched messages as kind and identifier pairs, in order. A group is identified by
     * its `type:localId`, the same key the helpers use.
     *
     * @return list<array{0: string, 1: int|string}>
     */
    private function sequence(): array
    {
        $sequence = [];
        foreach ($this->dispatched as $message) {
            if ($message instanceof SyncUserMessage) {
                $sequence[] = [
                    'user',
                    $message->getLidnr(),
                ];
            } elseif ($message instanceof SyncGroupMessage) {
                $sequence[] = [
                    'group',
                    $message->getLocalType()->value . ':' . $message->getLocalId(),
                ];
            } elseif ($message instanceof SyncGroupMembershipMessage) {
                $sequence[] = [
                    'membership',
                    $message->getLocalType()->value . ':' . $message->getLocalId(),
                ];
            }
        }

        return $sequence;
    }

    /**
     * Store a link for every local user and group whose payload matches the local state, so the
     * source and the link table agree. The user links go first: a group's payload hash is built
     * from the remote ids the links resolve to, and the command resolves them the same way.
     *
     * @param list<int>    $skipUsers
     * @param list<string> $skipGroupKeys
     */
    private function linkEverything(
        array $skipUsers = [],
        array $skipGroupKeys = [],
    ): void {
        $em = $this->entityManager;

        foreach ($this->localUsers() as $user) {
            $lidnr = (int) $user->member->lidnr;
            if (
                in_array(
                    $lidnr,
                    $skipUsers,
                    true,
                )
            ) {
                continue;
            }

            $em->persist(
                $this->linkFor(
                    ScimLocalTypes::User,
                    (string) $lidnr,
                    $this->userHash($user),
                    'user-remote-' . $lidnr,
                ),
            );
        }

        $em->flush();

        $linkRepo = self::getContainer()->get(ScimRemoteLinkRepository::class);
        $groupMapper = self::getContainer()->get(GroupScimMapper::class);
        foreach ($this->groupResolver->allGroupRefs() as $ref) {
            $key = $this->groupKey($ref);
            if (
                in_array(
                    $key,
                    $skipGroupKeys,
                    true,
                )
            ) {
                continue;
            }

            $localGroup = $this->groupResolver->resolve(
                $ref->localType,
                $ref->localId,
            );
            if (null === $localGroup) {
                continue;
            }

            $em->persist(
                $this->linkFor(
                    $ref->localType,
                    $ref->localId,
                    $groupMapper->map(
                        new GroupScimSource(
                            displayName: $localGroup->displayName,
                            externalId: $localGroup->externalId,
                            memberRemoteIds: array_values(
                                $linkRepo->findUserRemoteIds($localGroup->memberLidnrs),
                            ),
                        ),
                    )->payloadHash(),
                    'group-remote-' . $key,
                ),
            );
        }

        $em->flush();
    }

    private function linkFor(
        ScimLocalTypes $type,
        string $localId,
        string $hash,
        string $remoteId,
    ): ScimRemoteLink {
        $link = new ScimRemoteLink(
            $type,
            $localId,
            $remoteId,
        );
        $link->payloadHash = $hash;
        $link->lastSyncedAt = new DateTimeImmutable();

        return $link;
    }

    private function userHash(User $user): string
    {
        $member = $user->member;

        return self::getContainer()->get(UserScimMapper::class)->map(
            new UserScimSource(
                lidnr: (string) $member->lidnr,
                firstName: $member->firstName,
                lastName: $member->lastName,
                email: $member->email,
                active: !$member->hidden && !$member->deleted,
            ),
        )->payloadHash();
    }

    /**
     * Point the fake source at exactly what the local state and the links say, minus the excluded
     * objects. A group's members are the remote ids the links resolve to, matching the command's
     * own resolution; the source's list order is irrelevant because the content check sorts.
     *
     * @param list<int>    $excludeUsers
     * @param list<string> $excludeGroupKeys
     */
    private function sourceMirrorsLocal(
        array $excludeUsers = [],
        array $excludeGroupKeys = [],
    ): void {
        $this->client->listedUsers = array_values(
            array_map(
                static fn (User $user): ScimUser => new ScimUser(
                    userName: 'm' . $user->member->lidnr,
                    externalId: (string) $user->member->lidnr,
                    emails: null !== $user->member->email && '' !== $user->member->email
                        ? [
                            new ScimEmail(
                                $user->member->email,
                                primary: true,
                                type: 'work',
                            ),
                        ]
                        : [],
                    active: !$user->member->hidden && !$user->member->deleted,
                    remoteId: 'user-remote-' . $user->member->lidnr,
                ),
                array_filter(
                    $this->localUsers(),
                    static fn (User $user): bool => !in_array(
                        $user->member->lidnr,
                        $excludeUsers,
                        true,
                    ),
                ),
            ),
        );

        $linkRepo = self::getContainer()->get(ScimRemoteLinkRepository::class);
        $groups = [];
        foreach ($this->groupResolver->allGroupRefs() as $ref) {
            $key = $this->groupKey($ref);
            if (
                in_array(
                    $key,
                    $excludeGroupKeys,
                    true,
                )
            ) {
                continue;
            }

            $localGroup = $this->groupResolver->resolve(
                $ref->localType,
                $ref->localId,
            );
            if (null === $localGroup) {
                continue;
            }

            $groups[] = new ScimGroup(
                displayName: $localGroup->displayName,
                externalId: $localGroup->externalId,
                memberRemoteIds: array_values(
                    $linkRepo->findUserRemoteIds($localGroup->memberLidnrs),
                ),
                remoteId: 'group-remote-' . $key,
            );
        }

        $this->client->listedGroups = $groups;
    }

    private function groupKey(ResolvedScimGroupRef $ref): string
    {
        return $ref->localType->value . ':' . $ref->localId;
    }

    /**
     * The first body group the projection resolves, the smallest group kind to target an
     * individual test at.
     */
    private function aBodyGroupRef(): ResolvedScimGroupRef
    {
        foreach ($this->groupResolver->allGroupRefs() as $ref) {
            if (ScimLocalTypes::Body === $ref->localType) {
                return $ref;
            }
        }

        self::markTestSkipped('No body group in the seed.');
    }

    /**
     * @return list<User>
     */
    private function localUsers(): array
    {
        return self::getContainer()->get(UserRepository::class)->findAll();
    }
}
