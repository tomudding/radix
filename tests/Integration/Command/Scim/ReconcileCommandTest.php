<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command\Scim;

use App\Command\Scim\ReconcileCommand;
use App\Entity\Decision\Organ;
use App\Entity\User\Enums\ScimLocalTypes;
use App\Entity\User\ScimRemoteLink;
use App\Entity\User\User;
use App\Message\Scim\SyncGroupMembershipMessage;
use App\Message\Scim\SyncGroupMessage;
use App\Message\Scim\SyncUserMessage;
use App\Repository\Decision\OrganMemberRepository;
use App\Repository\Decision\OrganRepository;
use App\Repository\User\ScimRemoteLinkRepository;
use App\Repository\User\UserRepository;
use App\Service\Scim\GroupScimMapper;
use App\Service\Scim\GroupScimSource;
use App\Service\Scim\ScimEmail;
use App\Service\Scim\ScimGroup;
use App\Service\Scim\ScimGroupKey;
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
use function count;
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

    /** @var list<object> */
    private array $dispatched = [];

    private string $display = '';

    /** @var array<int, list<int>>|null organ id to the identifiers of its current members */
    private ?array $seatCache = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = new FakeScimClient();
        $this->logger = self::createStub(LoggerInterface::class);
        $this->dispatched = [];
        $this->display = '';
        $this->seatCache = null;

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
            'No drift, no orphans.',
            $this->display,
        );
    }

    public function testAMemberWithoutALinkIsDispatched(): void
    {
        $lidnr = $this->aSeatlessLidnr();

        $this->linkEverything(skipUsers: [$lidnr]);
        $this->sourceMirrorsLocal(excludeUsers: [$lidnr]);

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
        $organ = $this->activeOrgans()[0];
        $organId = (int) $organ->id;

        $this->linkEverything();
        $this->sourceMirrorsLocal();

        // A member added on the source side without a local write: the membership set differs,
        // the hash does not, so only the content check sees the drift.
        $foreign = 'user-remote-999999';
        foreach ($this->client->listedGroups as $i => $listed) {
            if ('group-remote-' . $organId === $listed->remoteId) {
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
                    $organId,
                ],
                [
                    'membership',
                    $organId,
                ],
            ],
            $this->sequence(),
        );
        self::assertStringContainsString(
            sprintf(
                'organ %d (%s): the source changed what was last synced',
                $organId,
                $organ->name,
            ),
            $this->display,
        );
    }

    public function testAVanishedRemoteUserIsDispatchedAndTheLinkIsDropped(): void
    {
        $lidnr = $this->localUsers()[0]->member->lidnr;

        $this->linkEverything();
        $this->sourceMirrorsLocal(excludeUsers: [$lidnr]);

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

        $this->invoke(['--dry-run' => true]);

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

    public function testAVanishedRemoteGroupIsDispatchedAndTheLinkIsDropped(): void
    {
        $organ = $this->activeOrgans()[0];
        $organId = (int) $organ->id;

        $this->linkEverything();
        $this->sourceMirrorsLocal(excludeGroups: [$organId]);

        $this->invoke();

        self::assertSame(
            [
                [
                    'group',
                    $organId,
                ],
                [
                    'membership',
                    $organId,
                ],
            ],
            $this->sequence(),
        );
        self::assertStringContainsString(
            sprintf(
                'organ %d (%s): the remote object is gone from the source',
                $organId,
                $organ->name,
            ),
            $this->display,
        );

        $this->entityManager->clear();
        self::assertNull(
            self::getContainer()->get(ScimRemoteLinkRepository::class)->findForGroup($organId),
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
            '1 user: m99999',
            $this->display,
        );
        self::assertStringNotContainsString(
            'akadmin',
            $this->display,
        );
    }

    public function testAGroupWithoutALinkDispatchesGroupAndMembership(): void
    {
        $organId = (int) $this->activeOrgans()[0]->id;

        $this->linkEverything(skipGroups: [$organId]);
        $this->sourceMirrorsLocal(excludeGroups: [$organId]);

        $this->invoke();

        self::assertSame(
            [
                [
                    'group',
                    $organId,
                ],
                [
                    'membership',
                    $organId,
                ],
            ],
            $this->sequence(),
        );
    }

    public function testAnUnlinkedMemberIsSyncedBeforeTheirOrganMembership(): void
    {
        [
            $organId, $lidnr
        ] = $this->aSingleSeat();

        $this->linkEverything(skipUsers: [$lidnr]);
        $this->sourceMirrorsLocal(excludeUsers: [$lidnr]);

        $this->invoke();

        self::assertSame(
            [
                [
                    'user',
                    $lidnr,
                ],
                [
                    'membership',
                    $organId,
                ],
            ],
            $this->sequence(),
        );
    }

    public function testADryRunDispatchesNothing(): void
    {
        $lidnr = $this->localUsers()[0]->member->lidnr;
        $this->linkEverything(skipUsers: [$lidnr]);
        $this->sourceMirrorsLocal(excludeUsers: [$lidnr]);

        $this->invoke(['--dry-run' => true]);

        self::assertSame(
            [],
            $this->dispatched,
        );
        self::assertStringContainsString(
            'Dry run; would dispatch',
            $this->display,
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

        foreach ($this->activeOrgans() as $organ) {
            $expected[] = [
                'group',
                (int) $organ->id,
            ];
            $expected[] = [
                'membership',
                (int) $organ->id,
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
        $organId = (int) $this->activeOrgans()[0]->id;

        $this->linkEverything(
            skipUsers: [$lidnr],
            skipGroups: [$organId],
        );
        $this->sourceMirrorsLocal(
            excludeUsers: [$lidnr],
            excludeGroups: [$organId],
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
        $this->command = new ReconcileCommand(
            $this->client,
            self::getContainer()->get(UserScimMapper::class),
            self::getContainer()->get(GroupScimMapper::class),
            self::getContainer()->get(UserRepository::class),
            self::getContainer()->get(OrganRepository::class),
            self::getContainer()->get(OrganMemberRepository::class),
            self::getContainer()->get(ScimRemoteLinkRepository::class),
            $this->bus,
            $this->logger,
            false,
        );
        $this->command->setRunLockFactory(
            self::getContainer()->get('lock.factory'),
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

    private function makeCommand(?LoggerInterface $logger = null): ReconcileCommand
    {
        $container = self::getContainer();
        $command = new ReconcileCommand(
            $this->client,
            $container->get(UserScimMapper::class),
            $container->get(GroupScimMapper::class),
            $container->get(UserRepository::class),
            $container->get(OrganRepository::class),
            $container->get(OrganMemberRepository::class),
            $container->get(ScimRemoteLinkRepository::class),
            $this->bus,
            $logger ?? $this->logger,
            true,
        );
        $command->setRunLockFactory(
            $container->get('lock.factory'),
        );

        return $command;
    }

    /**
     * The dispatched messages as kind and identifier pairs, in order.
     *
     * @return list<array{0: string, 1: int}>
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
                    $message->getOrganId(),
                ];
            } elseif ($message instanceof SyncGroupMembershipMessage) {
                $sequence[] = [
                    'membership',
                    $message->getOrganId(),
                ];
            }
        }

        return $sequence;
    }

    /**
     * Store a link for every local user and organ whose payload matches the local state, so the
     * source and the link table agree.
     *
     * @param list<int> $skipUsers
     * @param list<int> $skipGroups
     */
    private function linkEverything(
        array $skipUsers = [],
        array $skipGroups = [],
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
                    $lidnr,
                    $this->userHash($user),
                    'user-remote-' . $lidnr,
                ),
            );
        }

        // The user links go first: a group's payload hash is built from the remote ids the
        // links resolve to, and the command resolves them the same way.
        $em->flush();

        foreach ($this->activeOrgans() as $organ) {
            $organId = (int) $organ->id;
            if (
                in_array(
                    $organId,
                    $skipGroups,
                    true,
                )
            ) {
                continue;
            }

            $em->persist(
                $this->linkFor(
                    ScimLocalTypes::Group,
                    $organId,
                    $this->groupHash($organ),
                    'group-remote-' . $organId,
                ),
            );
        }

        $em->flush();
    }

    private function linkFor(
        ScimLocalTypes $type,
        int $localId,
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
                active: !$member->hidden,
            ),
        )->payloadHash();
    }

    private function groupHash(Organ $organ): string
    {
        $memberRemoteIds = self::getContainer()
            ->get(ScimRemoteLinkRepository::class)
            ->findUserRemoteIds($this->seats()[(int) $organ->id]);

        return self::getContainer()->get(GroupScimMapper::class)->map(
            new GroupScimSource(
                displayName: $organ->name,
                externalId: ScimGroupKey::for($organ),
                memberRemoteIds: array_values($memberRemoteIds),
            ),
        )->payloadHash();
    }

    /**
     * Point the fake source at exactly what the local state and the links say, minus the excluded
     * objects.
     *
     * @param list<int> $excludeUsers
     * @param list<int> $excludeGroups
     */
    private function sourceMirrorsLocal(
        array $excludeUsers = [],
        array $excludeGroups = [],
    ): void {
        $seats = $this->seats();

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
                    active: !$user->member->hidden,
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

        $this->client->listedGroups = array_values(
            array_map(
                static fn (Organ $organ): ScimGroup => new ScimGroup(
                    displayName: $organ->name,
                    externalId: ScimGroupKey::for($organ),
                    memberRemoteIds: array_map(
                        static fn (int $lidnr): string => 'user-remote-' . $lidnr,
                        $seats[(int) $organ->id],
                    ),
                    remoteId: 'group-remote-' . $organ->id,
                ),
                array_filter(
                    $this->activeOrgans(),
                    static fn (Organ $organ): bool => !in_array(
                        (int) $organ->id,
                        $excludeGroups,
                        true,
                    ),
                ),
            ),
        );
    }

    /**
     * An organ and one of its members who holds no other seat, so the member's drift touches one
     * organ only.
     *
     * @return array{0: int, 1: int}
     */
    private function aSingleSeat(): array
    {
        $occurrences = [];
        foreach ($this->seats() as $organId => $lidnrs) {
            foreach ($lidnrs as $lidnr) {
                $occurrences[$lidnr][] = $organId;
            }
        }

        foreach ($occurrences as $lidnr => $organIds) {
            if (1 === count($organIds)) {
                return [
                    $organIds[0],
                    $lidnr,
                ];
            }
        }

        self::markTestSkipped('No single-seat member in the seed.');
    }

    /**
     * A member without a link and without any seat, so their drift touches no organ.
     */
    private function aSeatlessLidnr(): int
    {
        $seated = [];
        foreach ($this->seats() as $lidnrs) {
            foreach ($lidnrs as $lidnr) {
                $seated[$lidnr] = true;
            }
        }

        foreach ($this->localUsers() as $user) {
            if (!isset($seated[$user->member->lidnr])) {
                return $user->member->lidnr;
            }
        }

        self::markTestSkipped('Every seeded member holds a seat.');
    }

    /**
     * @return list<User>
     */
    private function localUsers(): array
    {
        return self::getContainer()->get(UserRepository::class)->findAll();
    }

    /**
     * @return array<Organ>
     */
    private function activeOrgans(): array
    {
        return self::getContainer()->get(OrganRepository::class)->findActive();
    }

    /**
     * @return array<int, list<int>> organ id to the identifiers of its current members
     */
    private function seats(): array
    {
        if (null !== $this->seatCache) {
            return $this->seatCache;
        }

        $seats = [];
        $repository = self::getContainer()->get(OrganMemberRepository::class);
        foreach ($this->activeOrgans() as $organ) {
            $seats[(int) $organ->id] = $repository->findCurrentMemberLidnrs((int) $organ->id);
        }

        return $this->seatCache = $seats;
    }
}
