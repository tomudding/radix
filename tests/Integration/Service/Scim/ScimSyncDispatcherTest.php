<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Scim;

use App\Entity\Database\Enums\InstallationFunctions;
use App\Entity\Database\SubDecision\Board\Installation as LedgerBoardInstallation;
use App\Entity\Database\SubDecision\Installation as LedgerOrganInstallation;
use App\Entity\Database\SubDecision\Key\Granting as LedgerKeyGranting;
use App\Entity\User\Enums\ScimLocalTypes;
use App\Message\Scim\SyncGroupMembershipMessage;
use App\Message\Scim\SyncGroupMessage;
use App\Repository\Decision\MemberRepository;
use App\Service\Scim\ScimGroupResolver;
use App\Service\Scim\ScimSyncDispatcher;
use App\Tests\Integration\DatabaseTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

use function array_filter;
use function array_map;
use function array_values;
use function count;
use function sprintf;

/**
 * The register writes the dispatcher reacts to: a key grant or withdrawal dispatches the fixed
 * keyholders group, a board installation the current and recent drops plus the function drop of
 * the seat it fills.
 */
#[CoversClass(ScimSyncDispatcher::class)]
final class ScimSyncDispatcherTest extends DatabaseTestCase
{
    public function testAKeyGrantingDispatchesTheKeyholdersGroup(): void
    {
        $database = self::getContainer()->get('doctrine')->getManager('database');
        self::assertInstanceOf(
            EntityManagerInterface::class,
            $database,
        );
        $granting = $database->getRepository(LedgerKeyGranting::class)->findOneBy([]);
        self::assertNotNull($granting);

        $dispatched = [];
        $bus = self::createStub(MessageBusInterface::class);
        $bus->method('dispatch')
            ->willReturnCallback(static function (object $message, array $context = []) use (&$dispatched): Envelope {
                $dispatched[] = $message;

                return new Envelope($message);
            });

        $dispatcher = new ScimSyncDispatcher(
            $bus,
            self::getContainer()->get(MemberRepository::class),
            self::getContainer()->get(ScimGroupResolver::class),
            true,
        );
        $dispatcher->subDecisionChanged($granting);

        self::assertCount(
            2,
            $dispatched,
        );
        self::assertInstanceOf(
            SyncGroupMessage::class,
            $dispatched[0],
        );
        self::assertSame(
            ScimLocalTypes::Status,
            $dispatched[0]->getLocalType(),
        );
        self::assertSame(
            ScimGroupResolver::STATUS_KEYHOLDERS,
            $dispatched[0]->getLocalId(),
        );
        self::assertInstanceOf(
            SyncGroupMembershipMessage::class,
            $dispatched[1],
        );
        self::assertSame(
            ScimLocalTypes::Status,
            $dispatched[1]->getLocalType(),
        );
        self::assertSame(
            ScimGroupResolver::STATUS_KEYHOLDERS,
            $dispatched[1]->getLocalId(),
        );
    }

    public function testABoardInstallationDispatchesTheBoardDropsAndItsFunction(): void
    {
        $database = self::getContainer()->get('doctrine')->getManager('database');
        self::assertInstanceOf(
            EntityManagerInterface::class,
            $database,
        );
        $installation = $database->getRepository(LedgerBoardInstallation::class)->findOneBy([]);
        self::assertNotNull($installation);

        $dispatched = [];
        $bus = self::createStub(MessageBusInterface::class);
        $bus->method('dispatch')
            ->willReturnCallback(static function (object $message, array $context = []) use (&$dispatched): Envelope {
                $dispatched[] = $message;

                return new Envelope($message);
            });

        $dispatcher = new ScimSyncDispatcher(
            $bus,
            self::getContainer()->get(MemberRepository::class),
            self::getContainer()->get(ScimGroupResolver::class),
            true,
        );
        $dispatcher->subDecisionChanged($installation);

        $boardKeys = [
            ScimGroupResolver::BOARD_CURRENT,
            ScimGroupResolver::BOARD_RECENT,
            ScimGroupResolver::boardFunctionKey($installation->function),
        ];
        $boardMessages = array_filter(
            $dispatched,
            static fn (object $message): bool => $message instanceof SyncGroupMessage,
        );
        self::assertCount(
            count($boardKeys),
            $boardMessages,
        );
        self::assertSame(
            $boardKeys,
            array_map(
                static fn (SyncGroupMessage $message): string => $message->getLocalId(),
                array_values($boardMessages),
            ),
        );
    }

    public function testAnOrganInstallationDispatchesItsFunctionGroup(): void
    {
        $database = self::getContainer()->get('doctrine')->getManager('database');
        self::assertInstanceOf(
            EntityManagerInterface::class,
            $database,
        );
        $installation = null;
        foreach ($database->getRepository(LedgerOrganInstallation::class)->findAll() as $candidate) {
            if (InstallationFunctions::Chair === $candidate->function) {
                $installation = $candidate;

                break;
            }
        }

        self::assertNotNull($installation);

        $dispatched = [];
        $bus = self::createStub(MessageBusInterface::class);
        $bus->method('dispatch')
            ->willReturnCallback(static function (object $message, array $context = []) use (&$dispatched): Envelope {
                $dispatched[] = $message;

                return new Envelope($message);
            });

        $dispatcher = new ScimSyncDispatcher(
            $bus,
            self::getContainer()->get(MemberRepository::class),
            self::getContainer()->get(ScimGroupResolver::class),
            true,
        );
        $dispatcher->subDecisionChanged($installation);

        $functionMessages = array_values(array_filter(
            $dispatched,
            static fn (object $message): bool => $message instanceof SyncGroupMessage
                && ScimLocalTypes::BodyFunction === $message->getLocalType(),
        ));
        self::assertCount(
            1,
            $functionMessages,
        );
        self::assertSame(
            ScimGroupResolver::bodyFunctionKey(
                sprintf(
                    '%s-%d.%d.%d.%d',
                    $installation->foundation->getMeetingType()->value,
                    $installation->foundation->getMeetingNumber(),
                    $installation->foundation->getDecisionPoint(),
                    $installation->foundation->getDecisionNumber(),
                    $installation->foundation->sequence,
                ),
                InstallationFunctions::Chair,
            ),
            $functionMessages[0]->getLocalId(),
        );
    }

    public function testAnOrganInstallationDispatchesItsBodyGroup(): void
    {
        $database = self::getContainer()->get('doctrine')->getManager('database');
        self::assertInstanceOf(
            EntityManagerInterface::class,
            $database,
        );
        $installation = null;
        foreach ($database->getRepository(LedgerOrganInstallation::class)->findAll() as $candidate) {
            if (InstallationFunctions::Member === $candidate->function) {
                $installation = $candidate;

                break;
            }
        }

        self::assertNotNull($installation);
        $foundation = $installation->foundation;

        $dispatched = [];
        $bus = self::createStub(MessageBusInterface::class);
        $bus->method('dispatch')
            ->willReturnCallback(static function (object $message, array $context = []) use (&$dispatched): Envelope {
                $dispatched[] = $message;

                return new Envelope($message);
            });

        $dispatcher = new ScimSyncDispatcher(
            $bus,
            self::getContainer()->get(MemberRepository::class),
            self::getContainer()->get(ScimGroupResolver::class),
            true,
        );
        $dispatcher->subDecisionChanged($installation);

        $bodyMessages = array_filter(
            $dispatched,
            static fn (object $message): bool => $message instanceof SyncGroupMessage
                && ScimLocalTypes::Body === $message->getLocalType(),
        );
        self::assertCount(
            1,
            $bodyMessages,
        );
        self::assertSame(
            sprintf(
                '%s-%d.%d.%d.%d',
                $foundation->getMeetingType()->value,
                $foundation->getMeetingNumber(),
                $foundation->getDecisionPoint(),
                $foundation->getDecisionNumber(),
                $foundation->sequence,
            ),
            array_values($bodyMessages)[0]->getLocalId(),
        );
        self::assertCount(
            1,
            array_filter(
                $dispatched,
                static fn (object $message): bool => $message instanceof SyncGroupMembershipMessage
                    && ScimLocalTypes::Body === $message->getLocalType(),
            ),
        );
    }
}
