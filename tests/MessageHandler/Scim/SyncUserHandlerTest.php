<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Scim;

use App\Entity\User\Enums\ScimLocalTypes;
use App\Entity\User\ScimRemoteLink;
use App\Exception\Scim\ScimException;
use App\Exception\Scim\ScimNotFoundException;
use App\Message\Scim\SyncGroupMembershipMessage;
use App\Message\Scim\SyncUserMessage;
use App\MessageHandler\Scim\SyncUserHandler;
use App\Repository\User\ScimRemoteLinkRepository;
use App\Repository\User\UserRepository;
use App\Service\Scim\ScimGroupResolver;
use App\Service\Scim\UserScimMapper;
use App\Tests\Integration\DatabaseTestCase;
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

        if (null !== self::getContainer()->get(UserRepository::class)->find(self::MEMBER)) {
            return;
        }

        self::markTestSkipped('The seed member must have an account.');
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

    public function testItIgnoresAMemberWithoutAnAccount(): void
    {
        $this->handler()->__invoke(new SyncUserMessage(999_999));

        self::assertSame(
            [],
            $this->client->calls,
        );
        self::assertNull($this->link());
    }

    private function handler(bool $scimEnabled = true): SyncUserHandler
    {
        $container = self::getContainer();

        return new SyncUserHandler(
            $this->client,
            $container->get(UserScimMapper::class),
            $container->get(UserRepository::class),
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
        return self::getContainer()->get(ScimRemoteLinkRepository::class)->findForUser(self::MEMBER);
    }

    private function seedLink(
        string $remoteId,
        string $payloadHash,
    ): void {
        $link = new ScimRemoteLink(
            ScimLocalTypes::User,
            (string) self::MEMBER,
            $remoteId,
        );
        $link->payloadHash = $payloadHash;
        $this->entityManager->persist($link);
        $this->entityManager->flush();
    }
}
