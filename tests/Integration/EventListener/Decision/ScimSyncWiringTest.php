<?php

declare(strict_types=1);

namespace App\Tests\Integration\EventListener\Decision;

use App\Entity\Database\Member;
use App\Entity\Database\SubDecision\Discharge;
use App\Entity\Database\SubDecision\Installation;
use App\Entity\Decision\Organ;
use App\Message\Scim\SyncGroupMembershipMessage;
use App\Message\Scim\SyncGroupMessage;
use App\Message\Scim\SyncUserMessage;
use App\Repository\Decision\OrganMemberRepository;
use App\Service\Scim\ScimSyncDispatcher;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

use function array_slice;
use function count;
use function max;

/**
 * A write to the register becomes a SCIM message.
 *
 * These run the real projection listeners against the real test databases and assert on what lands on the SCIM
 * transport, which is in-memory under test and consumed by nothing. The transport keeps its whole history for the
 * life of the process, so each test counts what was sent before its write and asserts on the delta only.
 */
final class ScimSyncWiringTest extends DatabaseTestCase
{
    public function testCreatingAMemberSyncsTheirAccount(): void
    {
        $sentBefore = count($this->scimSent());

        $ledger = $this->ledger();
        $member = new Member();
        $member->firstName = 'SCIM';
        $member->middleName = '';
        $member->initials = '';
        $member->lastName = 'Sync';
        $member->changedOn = new DateTimeImmutable();
        $member->setBirth('1990-01-01');
        $member->setEmail('scim-sync@example.org');
        $ledger->persist($member);
        $ledger->flush();

        $dispatched = array_slice(
            $this->scimSent(),
            $sentBefore,
        );
        self::assertCount(
            1,
            $dispatched,
        );
        self::assertSame(
            [$member->lidnr],
            $this->idsOf(
                $dispatched,
                SyncUserMessage::class,
            ),
        );
    }

    public function testRenamingAMemberSyncsTheirAccount(): void
    {
        $sentBefore = count($this->scimSent());

        $member = $this->aMember();
        $member->firstName = 'SCIM';
        $this->ledger()->flush();

        $dispatched = array_slice(
            $this->scimSent(),
            $sentBefore,
        );
        self::assertCount(
            1,
            $dispatched,
        );
        self::assertSame(
            [$member->lidnr],
            $this->idsOf(
                $dispatched,
                SyncUserMessage::class,
            ),
        );
    }

    public function testHidingAMemberSyncsTheirAccount(): void
    {
        $sentBefore = count($this->scimSent());

        $member = $this->aMember();
        $member->hidden = true;
        $this->ledger()->flush();

        $dispatched = array_slice(
            $this->scimSent(),
            $sentBefore,
        );
        self::assertCount(
            1,
            $dispatched,
        );
        self::assertSame(
            [$member->lidnr],
            $this->idsOf(
                $dispatched,
                SyncUserMessage::class,
            ),
        );
    }

    public function testDischargingAnOrganMemberSyncsTheOrganAndItsMembership(): void
    {
        $sentBefore = count($this->scimSent());

        [
            $organ,, $installation
        ] = $this->anOpenSeat();
        $ledger = $this->ledger();

        // The decision's sequence must stay unique; the decision's own subdecisions carry the highest one so far.
        $sequence = 0;
        foreach ($installation->decision->getSubdecisions() as $existing) {
            $sequence = max(
                $sequence,
                $existing->sequence,
            );
        }

        $discharge = new Discharge();
        $discharge->installation = $installation;
        $discharge->sequence = $sequence + 1;
        $discharge->setDecision($installation->decision);
        $ledger->persist($discharge);
        $ledger->flush();

        $dispatched = array_slice(
            $this->scimSent(),
            $sentBefore,
        );
        self::assertSame(
            [
                [$organ->id ?? 0],
                [$organ->id ?? 0],
            ],
            [
                $this->idsOf(
                    $dispatched,
                    SyncGroupMessage::class,
                ),
                $this->idsOf(
                    $dispatched,
                    SyncGroupMembershipMessage::class,
                ),
            ],
        );
    }

    public function testDeletingAMemberSyncsTheirAccount(): void
    {
        $sentBefore = count($this->scimSent());

        $member = $this->aMemberNoDecisionNames();
        $lidnr = $member->lidnr;
        $this->ledger()->remove($member);
        $this->ledger()->flush();

        $dispatched = array_slice(
            $this->scimSent(),
            $sentBefore,
        );
        self::assertCount(
            1,
            $dispatched,
        );
        self::assertSame(
            [$lidnr],
            $this->idsOf(
                $dispatched,
                SyncUserMessage::class,
            ),
        );
    }

    public function testMemberRemovalSyncsEveryCurrentSeatOfTheMember(): void
    {
        $sentBefore = count($this->scimSent());

        // The deletion listener reads the seats before the projection removes them and dispatches with that
        // list; this exercises the dispatch for a member with a current seat, without removing the member.
        [, $member
        ] = $this->anOpenSeat();
        $organIds = self::getContainer()->get(OrganMemberRepository::class)->findCurrentOrganIds($member->lidnr);
        self::assertNotEmpty($organIds);

        self::getContainer()->get(ScimSyncDispatcher::class)->memberRemoved(
            $member->lidnr,
            $organIds,
        );

        $dispatched = array_slice(
            $this->scimSent(),
            $sentBefore,
        );
        self::assertSame(
            [
                $organIds,
                [$member->lidnr],
            ],
            [
                $this->idsOf(
                    $dispatched,
                    SyncGroupMembershipMessage::class,
                ),
                $this->idsOf(
                    $dispatched,
                    SyncUserMessage::class,
                ),
            ],
        );
    }

    /**
     * The envelopes the SCIM transport has sent so far in this process.
     *
     * @return array<Envelope>
     */
    private function scimSent(): array
    {
        $transport = self::getContainer()->get('messenger.transport.scim');
        self::assertInstanceOf(
            InMemoryTransport::class,
            $transport,
        );

        return $transport->getSent();
    }

    /**
     * The identifiers the messages of one type among the given envelopes carry.
     *
     * @template T of SyncUserMessage|SyncGroupMessage|SyncGroupMembershipMessage
     *
     * @param array<Envelope> $envelopes
     * @param class-string<T> $class
     *
     * @return list<int>
     */
    private function idsOf(
        array $envelopes,
        string $class,
    ): array {
        $ids = [];
        foreach ($envelopes as $envelope) {
            $message = $envelope->getMessage();
            if (!$message instanceof $class) {
                continue;
            }

            $ids[] = $message instanceof SyncUserMessage
                ? $message->getLidnr()
                : $message->getOrganId();
        }

        return $ids;
    }

    private function ledger(): EntityManagerInterface
    {
        $ledger = self::getContainer()->get('doctrine')->getManager('default');
        self::assertInstanceOf(
            EntityManagerInterface::class,
            $ledger,
        );

        return $ledger;
    }

    private function aMember(): Member
    {
        $member = $this->ledger()->getRepository(Member::class)->findOneBy([]);
        self::assertInstanceOf(
            Member::class,
            $member,
        );

        return $member;
    }

    /**
     * @return array{0: Organ, 1: Member, 2: Installation} An organ, one of its current members, and the open
     * installation that puts the member in that organ.
     */
    private function anOpenSeat(): array
    {
        $organMemberRepository = self::getContainer()->get(OrganMemberRepository::class);
        $ledger = $this->ledger();

        foreach ($this->entityManager->getRepository(Organ::class)->findActive() as $organ) {
            foreach ($organMemberRepository->findCurrentMemberLidnrs($organ->id ?? 0) as $lidnr) {
                $member = $ledger->find(
                    Member::class,
                    $lidnr,
                );
                if (null === $member) {
                    continue;
                }

                foreach ($ledger->getRepository(Installation::class)->findBy(['member' => $member]) as $installation) {
                    if (null !== $installation->discharge) {
                        continue;
                    }

                    // The seed's projection and the ledger can disagree about an older seat; the installation's
                    // foundation must be the organ's own, or the seat the test creates is not the one it discharges.
                    if (
                        $organ->foundation->getMeetingType() !== $installation->foundation->getMeetingType()
                        || $organ->foundation->getMeetingNumber() !== $installation->foundation->getMeetingNumber()
                        || $organ->foundation->getDecisionPoint() !== $installation->foundation->getDecisionPoint()
                        || $organ->foundation->getDecisionNumber() !== $installation->foundation->getDecisionNumber()
                        || $organ->foundation->sequence !== $installation->foundation->sequence
                    ) {
                        continue;
                    }

                    return [
                        $organ,
                        $member,
                        $installation,
                    ];
                }
            }
        }

        self::fail('The seed has no open installation for a current seat');
    }

    private function aMemberNoDecisionNames(): Member
    {
        // The member reference of a subdecision lives in a trait column the DQL base entity does not expose, so
        // the candidate search goes to the raw schema, which the ledger's migrations pin.
        $ledger = $this->ledger();
        $lidnr = $ledger->getConnection()->executeQuery(
            'SELECT m.lidnr FROM member m
             WHERE NOT EXISTS (SELECT 1 FROM address a WHERE a.lidnr = m.lidnr)
               AND NOT EXISTS (SELECT 1 FROM mailinglistmember mlm WHERE mlm.member = m.lidnr)
               AND NOT EXISTS (SELECT 1 FROM subdecision s WHERE s.lidnr = m.lidnr)
             LIMIT 1',
        )->fetchOne();
        self::assertNotNull($lidnr);

        $member = $ledger->find(
            Member::class,
            (int) $lidnr,
        );
        self::assertInstanceOf(
            Member::class,
            $member,
        );

        return $member;
    }
}
