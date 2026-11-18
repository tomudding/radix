<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Scim;

use App\Entity\User\Enums\ScimLocalTypes;
use App\Entity\User\ScimRemoteLink;
use App\Exception\Scim\ScimNotFoundException;
use App\Message\Scim\SyncGroupMessage;
use App\Message\Scim\SyncUserMessage;
use App\MessageHandler\Scim\SyncGroupHandler;
use App\Repository\Decision\OrganMemberRepository;
use App\Repository\Decision\OrganRepository;
use App\Repository\User\ScimRemoteLinkRepository;
use App\Service\Scim\GroupScimMapper;
use App\Tests\Integration\DatabaseTestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;

use function array_diff;
use function array_map;
use function array_values;
use function sort;
use function sprintf;

/**
 * @covers \App\MessageHandler\Scim\SyncGroupHandler
 */
final class SyncGroupHandlerTest extends DatabaseTestCase
{
    private const int ORGAN = 5;

    private FakeScimClient $client;
    private MessageBusInterface $bus;

    /** @var list<object> */
    private array $dispatched = [];

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

        if ([] !== $this->organMemberRepository()->findCurrentMemberLidnrs(self::ORGAN)) {
            return;
        }

        self::markTestSkipped('The seed organ must have current members.');
    }

    public function testItCreatesTheGroupWithTheCurrentMembers(): void
    {
        foreach ($this->organMemberRepository()->findCurrentMemberLidnrs(self::ORGAN) as $lidnr) {
            $this->seedUserLink($lidnr);
        }

        $this->handler()->__invoke(new SyncGroupMessage(self::ORGAN));

        self::assertSame(
            ['createGroup'],
            $this->client->calls,
        );
        self::assertCount(
            1,
            $this->client->createdGroups,
        );
        $organ = self::getContainer()->get(OrganRepository::class)->findOrgan(self::ORGAN);
        self::assertNotNull($organ);
        $group = $this->client->createdGroups[0];
        self::assertSame(
            $organ->name,
            $group->displayName,
        );
        self::assertSame(
            sprintf(
                '%s:%s',
                $organ->type->value,
                new AsciiSlugger()->slug($organ->name)->toString(),
            ),
            $group->externalId,
        );

        $expectedMembers = array_map(
            static fn (int $lidnr): string => 'user-remote-' . $lidnr,
            $this->organMemberRepository()->findCurrentMemberLidnrs(self::ORGAN),
        );
        $sentMembers = $group->memberRemoteIds;
        sort($expectedMembers);
        sort($sentMembers);
        self::assertSame(
            $expectedMembers,
            $sentMembers,
        );

        $link = $this->link();
        self::assertNotNull($link);
        self::assertSame(
            'group-remote-1',
            $link->remoteId,
        );
        self::assertNotNull($link->payloadHash);
        self::assertNull($link->lastError);
    }

    public function testItDispatchesUserSyncsAndDefersWhenAMemberHasNoLink(): void
    {
        // Only the first current member is linked; the rest must wait for their user sync.
        $lidnrs = $this->organMemberRepository()->findCurrentMemberLidnrs(self::ORGAN);
        $this->seedUserLink(array_values($lidnrs)[0]);

        $this->handler()->__invoke(new SyncGroupMessage(self::ORGAN));

        self::assertSame(
            [],
            $this->client->calls,
        );
        self::assertNull($this->link());

        $expected = array_map(
            static fn (int $lidnr): SyncUserMessage => new SyncUserMessage($lidnr),
            array_values(array_diff($lidnrs, [array_values($lidnrs)[0]])),
        );
        self::assertEquals(
            $expected,
            $this->dispatched,
        );
    }

    public function testItSkipsWhenThePayloadIsUnchanged(): void
    {
        foreach ($this->organMemberRepository()->findCurrentMemberLidnrs(self::ORGAN) as $lidnr) {
            $this->seedUserLink($lidnr);
        }

        $handler = $this->handler();
        $handler->__invoke(new SyncGroupMessage(self::ORGAN));
        $this->client->calls = [];

        $handler->__invoke(new SyncGroupMessage(self::ORGAN));

        self::assertSame(
            [],
            $this->client->calls,
        );
    }

    public function testItRecreatesWhenTheRemoteGroupVanished(): void
    {
        foreach ($this->organMemberRepository()->findCurrentMemberLidnrs(self::ORGAN) as $lidnr) {
            $this->seedUserLink($lidnr);
        }

        $link = new ScimRemoteLink(
            ScimLocalTypes::Group,
            self::ORGAN,
            'group-remote-old',
        );
        $link->payloadHash = 'a-stale-hash';
        $this->entityManager->persist($link);
        $this->entityManager->flush();
        $this->client->updateGroupFailure = new ScimNotFoundException('PUT /Groups/group-remote-old');

        $this->handler()->__invoke(new SyncGroupMessage(self::ORGAN));

        self::assertSame(
            [
                'updateGroup',
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
        $this->handler(scimEnabled: false)->__invoke(new SyncGroupMessage(self::ORGAN));

        self::assertSame(
            [],
            $this->client->calls,
        );
        self::assertNull($this->link());
    }

    public function testItRefusesAnOrganThatSharesItsTypeAndNameWithAnActiveOrgan(): void
    {
        $this->insertSiblingOrgan(null);

        $logger = self::createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning');

        $this->handler(logger: $logger)->__invoke(new SyncGroupMessage(self::ORGAN));

        self::assertSame(
            [],
            $this->client->calls,
        );
        self::assertNull($this->link());
    }

    public function testItSyncsAnOrganWhoseOnlySiblingIsAbrogated(): void
    {
        $this->insertSiblingOrgan('2020-01-01');

        foreach ($this->organMemberRepository()->findCurrentMemberLidnrs(self::ORGAN) as $lidnr) {
            $this->seedUserLink($lidnr);
        }

        $this->handler()->__invoke(new SyncGroupMessage(self::ORGAN));

        self::assertSame(
            [
                'createGroup',
            ],
            $this->client->calls,
        );
    }

    /**
     * Insert a second organ of the same type and name as the organ under test, so the
     * handler's shared-key guard sees it; the test transaction rolls the row back.
     */
    private function insertSiblingOrgan(?string $abrogationDate): void
    {
        $organ = self::getContainer()->get(OrganRepository::class)->findOrgan(self::ORGAN);
        self::assertNotNull($organ);

        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO Organ (abbr, name, type, foundationDate, abrogationDate) VALUES (?, ?, ?, ?, ?)',
            [
                $organ->abbr,
                $organ->name,
                $organ->type->value,
                $organ->foundationDate->format('Y-m-d'),
                $abrogationDate,
            ],
        );
    }

    private function handler(
        bool $scimEnabled = true,
        ?LoggerInterface $logger = null,
    ): SyncGroupHandler {
        $container = self::getContainer();

        return new SyncGroupHandler(
            $this->client,
            $container->get(GroupScimMapper::class),
            $container->get(OrganRepository::class),
            $container->get(OrganMemberRepository::class),
            $container->get(ScimRemoteLinkRepository::class),
            $this->entityManager,
            $this->bus,
            $logger ?? self::createStub(LoggerInterface::class),
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

    private function seedUserLink(int $lidnr): void
    {
        $this->entityManager->persist(
            new ScimRemoteLink(
                ScimLocalTypes::User,
                $lidnr,
                'user-remote-' . $lidnr,
            ),
        );
        $this->entityManager->flush();
    }
}
