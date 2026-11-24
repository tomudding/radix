<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Scim;

use App\Entity\Database\Enums\MembershipTypes;
use App\Entity\Database\Enums\Studies;
use App\Entity\Decision\Member;
use App\Entity\User\Enums\ScimLocalTypes;
use App\Entity\User\ScimRemoteLink;
use App\Exception\Scim\ScimException;
use App\Exception\Scim\ScimNotFoundException;
use App\Message\Scim\SyncGroupMembershipMessage;
use App\Message\Scim\SyncUserMessage;
use App\MessageHandler\Scim\SyncUserHandler;
use App\Repository\Decision\MemberRepository;
use App\Repository\User\ScimRemoteLinkRepository;
use App\Service\Scim\ScimGroupResolver;
use App\Service\Scim\UserScimMapper;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

use function array_map;

/**
 * @covers \App\MessageHandler\Scim\SyncUserHandler
 */
final class SyncUserHandlerTest extends DatabaseTestCase
{
    private const int MEMBER = 8000;

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
    }

    public function testItCreatesTheRemoteUserAndStoresTheLink(): void
    {
        $this->handler()->__invoke(new SyncUserMessage(self::MEMBER));

        self::assertSame(
            ['createUser'],
            $this->client->calls,
        );
        self::assertCount(
            1,
            $this->client->createdUsers,
        );
        $sent = $this->client->createdUsers[0];
        self::assertSame(
            'm8000',
            $sent->userName,
        );
        self::assertSame(
            '8000',
            $sent->externalId,
        );
        self::assertTrue($sent->active);

        $link = $this->link();
        self::assertNotNull($link);
        self::assertSame(
            ScimLocalTypes::User,
            $link->localType,
        );
        self::assertSame(
            (string) self::MEMBER,
            $link->localId,
        );
        self::assertSame(
            'user-remote-1',
            $link->remoteId,
        );
        self::assertNotNull($link->payloadHash);
        self::assertNotNull($link->lastSyncedAt);
        self::assertNull($link->lastError);
    }

    public function testItSkipsWhenThePayloadIsUnchanged(): void
    {
        $handler = $this->handler();
        $handler->__invoke(new SyncUserMessage(self::MEMBER));
        $this->client->calls = [];

        $handler->__invoke(new SyncUserMessage(self::MEMBER));

        self::assertSame(
            [],
            $this->client->calls,
        );
    }

    public function testItUpdatesWhenThePayloadChanged(): void
    {
        $this->seedLink(
            'user-remote-old',
            'a-stale-hash',
        );

        $this->handler()->__invoke(new SyncUserMessage(self::MEMBER));

        self::assertSame(
            ['updateUser'],
            $this->client->calls,
        );
        $link = $this->link();
        self::assertNotNull($link);
        self::assertSame(
            'user-remote-old',
            $link->remoteId,
        );
        self::assertNotNull($link->payloadHash);
        self::assertNotSame(
            'a-stale-hash',
            $link->payloadHash,
        );
    }

    public function testItRecreatesWhenTheRemoteObjectVanished(): void
    {
        $this->seedLink(
            'user-remote-old',
            'a-stale-hash',
        );
        $this->client->updateUserFailure = new ScimNotFoundException('PUT /Users/user-remote-old');

        $this->handler()->__invoke(new SyncUserMessage(self::MEMBER));

        self::assertSame(
            [
                'updateUser',
                'createUser',
            ],
            $this->client->calls,
        );
        $link = $this->link();
        self::assertNotNull($link);
        self::assertSame(
            'user-remote-1',
            $link->remoteId,
        );
        self::assertNull($link->lastError);
    }

    public function testItRecordsTheErrorAndRethrowsOnAFailure(): void
    {
        $this->seedLink(
            'user-remote-old',
            'a-stale-hash',
        );
        $this->client->updateUserFailure = new ScimException(
            'PUT /Users/user-remote-old',
            401,
            false,
            'Invalid token',
        );

        $caught = null;
        try {
            $this->handler()->__invoke(new SyncUserMessage(self::MEMBER));
        } catch (ScimException $exception) {
            $caught = $exception;
        }

        self::assertNotNull($caught);
        $link = $this->link();
        self::assertNotNull($link);
        self::assertSame(
            'user-remote-old',
            $link->remoteId,
        );
        self::assertStringContainsString(
            '401',
            (string) $link->lastError,
        );
        self::assertStringContainsString(
            'Invalid token',
            (string) $link->lastError,
        );
    }

    public function testItDispatchesAMembershipSyncForEachGroupOfTheMember(): void
    {
        $this->handler()->__invoke(new SyncUserMessage(self::MEMBER));

        $expected = array_map(
            static fn ($ref): SyncGroupMembershipMessage => new SyncGroupMembershipMessage(
                $ref->localType,
                $ref->localId,
            ),
            self::getContainer()->get(ScimGroupResolver::class)->refsForMember(self::MEMBER),
        );
        self::assertEquals(
            $expected,
            $this->dispatched,
        );
    }

    public function testItDoesNothingWhenDisabled(): void
    {
        $this->handler(scimEnabled: false)->__invoke(new SyncUserMessage(self::MEMBER));

        self::assertSame(
            [],
            $this->client->calls,
        );
        self::assertSame(
            [],
            $this->dispatched,
        );
        self::assertNull($this->link());
    }

    public function testItSyncsAMemberWithoutAWebsiteAccount(): void
    {
        $this->createMemberWithoutAccount(999_999);

        $this->handler()->__invoke(new SyncUserMessage(999_999));

        self::assertSame(
            ['createUser'],
            $this->client->calls,
        );
        self::assertSame(
            'm999999',
            $this->client->createdUsers[0]->userName,
        );
    }

    public function testItMarksALapsedMemberInactive(): void
    {
        $member = $this->createMemberWithoutAccount(999_997);
        $member->expiration = new DateTimeImmutable('-1 day');
        $this->entityManager->flush();

        $this->handler()->__invoke(new SyncUserMessage(999_997));

        self::assertSame(
            ['createUser'],
            $this->client->calls,
        );
        self::assertFalse($this->client->createdUsers[0]->active);
    }

    public function testItMarksALapsedGraduateInactive(): void
    {
        $member = $this->createMemberWithoutAccount(999_996);
        $member->type = MembershipTypes::Graduate;
        $member->expiration = new DateTimeImmutable('-1 day');
        $this->entityManager->flush();

        $this->handler()->__invoke(new SyncUserMessage(999_996));

        self::assertSame(
            ['createUser'],
            $this->client->calls,
        );
        self::assertFalse($this->client->createdUsers[0]->active);
    }

    public function testItDeactivatesAMemberTheRegisterNoLongerHolds(): void
    {
        $this->seedLink(
            'user-remote-old',
            'a-stale-hash',
            lidnr: 999_998,
        );

        $this->handler()->__invoke(new SyncUserMessage(999_998));

        self::assertSame(
            ['patchUser'],
            $this->client->calls,
        );
        self::assertCount(
            1,
            $this->client->userPatches,
        );
        self::assertSame(
            'user-remote-old',
            $this->client->userPatches[0][0],
        );

        $link = $this->linkFor(999_998);
        self::assertNotNull($link);
        self::assertSame(
            self::getContainer()->get(UserScimMapper::class)
                ->deactivated(999_998)
                ->payloadHash(),
            $link->payloadHash,
        );
        self::assertNull($link->lastError);
    }

    private function handler(bool $scimEnabled = true): SyncUserHandler
    {
        $container = self::getContainer();

        return new SyncUserHandler(
            $this->client,
            $container->get(UserScimMapper::class),
            $container->get(MemberRepository::class),
            $container->get(ScimRemoteLinkRepository::class),
            $container->get(ScimGroupResolver::class),
            $this->entityManager,
            $this->bus,
            self::createStub(LoggerInterface::class),
            $scimEnabled,
        );
    }

    private function link(): ?ScimRemoteLink
    {
        return $this->linkFor(self::MEMBER);
    }

    private function linkFor(int $lidnr): ?ScimRemoteLink
    {
        return self::getContainer()->get(ScimRemoteLinkRepository::class)->findForUser($lidnr);
    }

    private function seedLink(
        string $remoteId,
        string $payloadHash,
        int $lidnr = self::MEMBER,
    ): void {
        $link = new ScimRemoteLink(
            ScimLocalTypes::User,
            (string) $lidnr,
            $remoteId,
        );
        $link->payloadHash = $payloadHash;
        $this->entityManager->persist($link);
        $this->entityManager->flush();
    }

    private function createMemberWithoutAccount(int $lidnr): Member
    {
        $member = new Member();
        $member->lidnr = $lidnr;
        $member->firstName = 'No';
        $member->middleName = '';
        $member->initials = 'N';
        $member->lastName = 'Account';
        $member->generation = 2024;
        $member->type = MembershipTypes::Ordinary;
        $member->study = Studies::Unknown;
        $member->changedOn = new DateTimeImmutable();
        $member->expiration = new DateTimeImmutable('+1 year');
        $member->birth = new DateTimeImmutable('2000-01-01');
        $this->entityManager->persist($member);
        $this->entityManager->flush();

        return $member;
    }
}
