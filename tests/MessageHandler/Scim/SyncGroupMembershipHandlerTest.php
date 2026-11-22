<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Scim;

use App\Entity\User\Enums\ScimLocalTypes;
use App\Entity\User\ScimRemoteLink;
use App\Exception\Scim\ScimNotFoundException;
use App\Message\Scim\SyncGroupMembershipMessage;
use App\MessageHandler\Scim\SyncGroupMembershipHandler;
use App\Repository\Decision\OrganMemberRepository;
use App\Repository\Decision\OrganRepository;
use App\Repository\User\ScimRemoteLinkRepository;
use App\Service\Scim\GroupScimMapper;
use App\Service\Scim\ScimGroupResolver;
use App\Tests\Integration\DatabaseTestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

use function array_map;
use function count;

/**
 * @covers \App\MessageHandler\Scim\SyncGroupMembershipHandler
 */
final class SyncGroupMembershipHandlerTest extends DatabaseTestCase
{
    private const int ORGAN = 6;

    private FakeScimClient $client;
    private MessageBusInterface $bus;

    /**
     * The seed body's current members.
     *
     * @var list<int>
     */
    private array $memberLidnrs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = new FakeScimClient();
        $this->bus = self::createStub(MessageBusInterface::class);
        $this->bus->method('dispatch')
            ->willReturnCallback(static function (object $message, array $context = []): Envelope {
                return new Envelope($message);
            });

        $this->memberLidnrs = $this->organMemberRepository()->findCurrentMemberLidnrs(self::ORGAN);
        if (0 !== count($this->memberLidnrs)) {
            return;
        }

        self::markTestSkipped('The seed body has no current members.');
    }

    public function testItCreatesTheGroupWhenItDoesNotExistYet(): void
    {
        $this->seedUserLinks();

        $this->handler()->__invoke($this->message());

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

        $this->handler()->__invoke($this->message());

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

        $link = $this->link();
        self::assertNotNull($link);
        self::assertSame(
            'group-remote-1',
            $link->remoteId,
        );
    }

    public function testItRecreatesWhenTheRemoteGroupVanished(): void
    {
        $this->seedUserLinks();
        $this->seedGroupLink();
        $this->client->patchGroupMembersFailure = new ScimNotFoundException('PATCH /Groups/group-remote-1');

        $this->handler()->__invoke($this->message());

        self::assertSame(
            [
                'patchGroupMembers',
                'createGroup',
            ],
            $this->client->calls,
        );
        $link = $this->link();
        self::assertNotNull($link);
        self::assertSame(
            'group-remote-1',
            $link->remoteId,
        );
    }

    public function testItDoesNothingWhenDisabled(): void
    {
        $this->handler(scimEnabled: false)->__invoke($this->message());

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
            $container->get(ScimGroupResolver::class),
            $container->get(ScimRemoteLinkRepository::class),
            $this->entityManager,
            $this->bus,
            self::createStub(LoggerInterface::class),
            $scimEnabled,
        );
    }

    private function message(): SyncGroupMembershipMessage
    {
        $organ = self::getContainer()->get(OrganRepository::class)->findOrgan(self::ORGAN);
        self::assertNotNull($organ);

        return new SyncGroupMembershipMessage(
            ScimLocalTypes::Body,
            $organ->foundation->getHash(),
        );
    }

    /**
     * @return list<string>
     */
    private function memberRemoteIds(): array
    {
        return array_map(
            static fn (int $lidnr): string => 'user-remote-' . $lidnr,
            $this->memberLidnrs,
        );
    }

    private function organMemberRepository(): OrganMemberRepository
    {
        return self::getContainer()->get(OrganMemberRepository::class);
    }

    private function link(): ?ScimRemoteLink
    {
        $organ = self::getContainer()->get(OrganRepository::class)->findOrgan(self::ORGAN);
        self::assertNotNull($organ);

        return self::getContainer()->get(ScimRemoteLinkRepository::class)->findByLocal(
            ScimLocalTypes::Body,
            $organ->foundation->getHash(),
        );
    }

    private function seedUserLinks(): void
    {
        foreach ($this->memberLidnrs as $lidnr) {
            $this->entityManager->persist(
                new ScimRemoteLink(
                    ScimLocalTypes::User,
                    (string) $lidnr,
                    'user-remote-' . $lidnr,
                ),
            );
        }

        $this->entityManager->flush();
    }

    private function seedGroupLink(): void
    {
        $organ = self::getContainer()->get(OrganRepository::class)->findOrgan(self::ORGAN);
        self::assertNotNull($organ);

        $this->entityManager->persist(
            new ScimRemoteLink(
                ScimLocalTypes::Body,
                $organ->foundation->getHash(),
                'group-remote-1',
            ),
        );
        $this->entityManager->flush();
    }
}
