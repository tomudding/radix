<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Scim;

use App\Entity\User\Enums\ScimLocalTypes;
use App\Entity\User\ScimRemoteLink;
use App\Exception\Scim\ScimNotFoundException;
use App\Message\Scim\SyncGroupMembershipMessage;
use App\Message\Scim\SyncUserMessage;
use App\MessageHandler\Scim\SyncGroupMembershipHandler;
use App\Repository\Decision\OrganMemberRepository;
use App\Repository\Decision\OrganRepository;
use App\Repository\User\ScimRemoteLinkRepository;
use App\Service\Scim\GroupScimMapper;
use App\Tests\Integration\DatabaseTestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

use function array_map;
use function array_slice;
use function count;

/**
 * @covers \App\MessageHandler\Scim\SyncGroupMembershipHandler
 */
final class SyncGroupMembershipHandlerTest extends DatabaseTestCase
{
    private const int ORGAN = 6;

    private FakeScimClient $client;
    private MessageBusInterface $bus;

    /** @var list<object> */
    private array $dispatched = [];

    /**
     * The seed organ's current members; the tests derive from the seed rather than asserting a
     * fixed roster, and skip when the seed stops installing this organ.
     *
     * @var list<int>
     */
    private array $memberLidnrs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = new FakeScimClient();
        $this->dispatched = [];
        $this->bus = self::createStub(MessageBusInterface::class);
        $this->bus->method('dispatch')
            ->willReturnCallback(function (object $message, array $context = []): Envelope {
                $this->dispatched[] = $message;

                return new Envelope($message);
            });

        $this->memberLidnrs = $this->organMemberRepository()->findCurrentMemberLidnrs(self::ORGAN);
        if (0 !== count($this->memberLidnrs)) {
            return;
        }

        self::markTestSkipped('The seed organ has no current members.');
    }

    public function testItCreatesTheGroupWhenItDoesNotExistYet(): void
    {
        $this->seedUserLinks();

        $this->handler()->__invoke(new SyncGroupMembershipMessage(self::ORGAN));

        self::assertSame(
            ['createGroup'],
            $this->client->calls,
        );
        self::assertCount(
            1,
            $this->client->createdGroups,
        );
        self::assertEqualsCanonicalizing(
            $this->memberRemoteIds(),
            $this->client->createdGroups[0]->memberRemoteIds,
        );

        $link = $this->link();
        self::assertNotNull($link);
        self::assertSame(
            'group-remote-1',
            $link->remoteId,
        );
        self::assertNotNull($link->payloadHash);
    }

    public function testItPatchesOnlyTheDelta(): void
    {
        $this->seedUserLinks();
        $this->seedGroupLink();
        $this->client->reportedMembership = [
            'group-stranger',
            ...$this->memberRemoteIds(),
        ];

        $this->handler()->__invoke(new SyncGroupMembershipMessage(self::ORGAN));

        // One read of the current membership, one patch of the delta only.
        self::assertSame(
            [
                'patchGroupMembers',
                'patchGroupMembers',
            ],
            $this->client->calls,
        );
        self::assertSame(
            [
                [
                    [],
                    ['group-stranger'],
                ],
            ],
            $this->client->patches,
        );
    }

    public function testItAddsAMissingMember(): void
    {
        $this->seedUserLinks();
        $this->seedGroupLink();
        $missing = $this->remoteId($this->memberLidnrs[0]);
        $this->client->reportedMembership = array_slice(
            $this->memberRemoteIds(),
            1,
        );

        $this->handler()->__invoke(new SyncGroupMembershipMessage(self::ORGAN));

        self::assertSame(
            [
                [
                    [$missing],
                    [],
                ],
            ],
            $this->client->patches,
        );
    }

    public function testItDoesNothingWhenTheMembershipMatches(): void
    {
        $this->seedUserLinks();
        $this->seedGroupLink();
        $this->client->reportedMembership = $this->memberRemoteIds();

        $this->handler()->__invoke(new SyncGroupMembershipMessage(self::ORGAN));

        // The read alone; no patch follows.
        self::assertSame(
            ['patchGroupMembers'],
            $this->client->calls,
        );
        self::assertSame(
            [],
            $this->client->patches,
        );
    }

    public function testItDispatchesUserSyncsAndDefersWhenALinkIsMissing(): void
    {
        $this->handler()->__invoke(new SyncGroupMembershipMessage(self::ORGAN));

        self::assertSame(
            [],
            $this->client->calls,
        );
        self::assertNull($this->link());
        $expected = array_map(
            static fn (int $lidnr): SyncUserMessage => new SyncUserMessage($lidnr),
            $this->memberLidnrs,
        );
        self::assertEquals(
            $expected,
            $this->dispatched,
        );
    }

    public function testItRecreatesTheGroupWhenItVanished(): void
    {
        $this->seedUserLinks();
        $this->seedGroupLink();
        $this->client->patchGroupMembersFailure = new ScimNotFoundException('PATCH /Groups/group-remote-6');

        $this->handler()->__invoke(new SyncGroupMembershipMessage(self::ORGAN));

        self::assertSame(
            [
                'patchGroupMembers',
                'createGroup',
            ],
            $this->client->calls,
        );
        self::assertSame(
            'group-remote-1',
            $this->link()?->remoteId,
        );
    }

    public function testItDoesNothingWhenDisabled(): void
    {
        $this->handler(scimEnabled: false)->__invoke(new SyncGroupMembershipMessage(self::ORGAN));

        self::assertSame(
            [],
            $this->client->calls,
        );
        self::assertNull($this->link());
    }

    private function handler(bool $scimEnabled = true): SyncGroupMembershipHandler
    {
        $container = self::getContainer();

        return new SyncGroupMembershipHandler(
            $this->client,
            $container->get(GroupScimMapper::class),
            $container->get(OrganRepository::class),
            $container->get(OrganMemberRepository::class),
            $container->get(ScimRemoteLinkRepository::class),
            $this->entityManager,
            $this->bus,
            self::createStub(LoggerInterface::class),
            $scimEnabled,
        );
    }

    private function organMemberRepository(): OrganMemberRepository
    {
        return self::getContainer()->get(OrganMemberRepository::class);
    }

    private function link(): ?ScimRemoteLink
    {
        return self::getContainer()->get(ScimRemoteLinkRepository::class)->findForGroup(self::ORGAN);
    }

    private function seedUserLinks(): void
    {
        foreach ($this->memberLidnrs as $lidnr) {
            $this->entityManager->persist(
                new ScimRemoteLink(
                    ScimLocalTypes::User,
                    $lidnr,
                    $this->remoteId($lidnr),
                ),
            );
        }

        $this->entityManager->flush();
    }

    private function seedGroupLink(): void
    {
        $this->entityManager->persist(
            new ScimRemoteLink(
                ScimLocalTypes::Group,
                self::ORGAN,
                'group-remote-6',
            ),
        );
        $this->entityManager->flush();
    }

    private function remoteId(int $lidnr): string
    {
        return 'user-remote-' . $lidnr;
    }

    /**
     * @return list<string>
     */
    private function memberRemoteIds(): array
    {
        return array_map(
            fn (int $lidnr): string => $this->remoteId($lidnr),
            $this->memberLidnrs,
        );
    }
}
