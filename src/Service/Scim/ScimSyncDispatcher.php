<?php

declare(strict_types=1);

namespace App\Service\Scim;

use App\Entity\Database\Decision as LedgerDecision;
use App\Entity\Database\SubDecision;
use App\Entity\Database\SubDecision\Annulment;
use App\Entity\Database\SubDecision\Discharge;
use App\Entity\Database\SubDecision\Foundation;
use App\Entity\Database\SubDecision\FoundationReference;
use App\Entity\Decision\Organ;
use App\Message\Scim\SyncGroupMembershipMessage;
use App\Message\Scim\SyncGroupMessage;
use App\Message\Scim\SyncUserMessage;
use App\Repository\Decision\OrganMemberRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

use function array_unique;
use function array_values;

/**
 * Dispatches the SCIM sync messages for a register write, once the projection change behind it
 * has been committed, and when a member's first account comes into existence (password reset;
 * the ledger row predates the account). The handlers re-read the state themselves, so only
 * identifiers travel.
 */
final class ScimSyncDispatcher
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly OrganMemberRepository $organMemberRepository,
        #[Autowire(service: 'doctrine.orm.web_entity_manager')]
        private readonly EntityManagerInterface $emWeb,
        private readonly bool $scimEnabled,
    ) {
    }

    /**
     * A member's account data has changed, or their account has come into existence; sync the account.
     */
    public function memberChanged(int $lidnr): void
    {
        if (!$this->scimEnabled) {
            return;
        }

        $this->messageBus->dispatch(new SyncUserMessage($lidnr));
    }

    /**
     * The organs a member is currently installed in.
     *
     * Read before the member's deletion, because the projection no longer lists the member afterwards.
     *
     * @return list<int>
     */
    public function currentOrganIds(int $lidnr): array
    {
        if (!$this->scimEnabled) {
            return [];
        }

        return $this->organMemberRepository->findCurrentOrganIds($lidnr);
    }

    /**
     * A member has been removed from the register.
     *
     * The organs are passed in, read while the projection still contained the member, and the
     * caller dispatches only after that deletion has committed: a message consumed before the
     * commit reads the member as still current and changes nothing. The membership handlers
     * remove the member from whatever the source still lists; the user message keeps the
     * account's bookkeeping in line with a member that no longer exists.
     *
     * @param list<int> $organIds
     */
    public function memberRemoved(
        int $lidnr,
        array $organIds,
    ): void {
        if (!$this->scimEnabled) {
            return;
        }

        foreach ($organIds as $organId) {
            $this->messageBus->dispatch(new SyncGroupMembershipMessage($organId));
        }

        $this->messageBus->dispatch(new SyncUserMessage($lidnr));
    }

    /**
     * A subdecision has been written to the register; sync the groups its projection touches.
     */
    public function subDecisionChanged(SubDecision $subDecision): void
    {
        if (!$this->scimEnabled) {
            return;
        }

        foreach ($this->affectedOrganIds($subDecision) as $organId) {
            $this->messageBus->dispatch(new SyncGroupMessage($organId));
            $this->messageBus->dispatch(new SyncGroupMembershipMessage($organId));
        }
    }

    /**
     * The organs a subdecision's projection change touches.
     *
     * @return list<int>
     */
    private function affectedOrganIds(SubDecision $subDecision): array
    {
        $organIds = [];
        foreach ($this->foundationsTouched($subDecision) as $foundation) {
            $organId = $this->organIdOf($foundation);
            if (null === $organId) {
                continue;
            }

            $organIds[] = $organId;
        }

        return array_values(array_unique($organIds));
    }

    /**
     * The foundations a subdecision's projection touches.
     *
     * An annulment reverts the projection of its target decision, so the touched foundations are those of the
     * target decision's subdecisions; an annulment of an annulment reverts them again, hence the recursion.
     *
     * @return list<Foundation>
     */
    private function foundationsTouched(SubDecision $subDecision): array
    {
        if ($subDecision instanceof Annulment) {
            return $this->foundationsOf($subDecision->target);
        }

        $foundation = $this->foundationOf($subDecision);

        return null === $foundation
            ? []
            : [$foundation];
    }

    /**
     * @return list<Foundation>
     */
    private function foundationsOf(LedgerDecision $decision): array
    {
        $foundations = [];
        foreach ($decision->getSubdecisions() as $subDecision) {
            if ($subDecision instanceof Annulment) {
                $foundations = [
                    ...$foundations,
                    ...$this->foundationsOf($subDecision->target),
                ];
                continue;
            }

            $foundation = $this->foundationOf($subDecision);
            if (null === $foundation) {
                continue;
            }

            $foundations[] = $foundation;
        }

        return $foundations;
    }

    /**
     * The foundation a subdecision projects, when it projects one.
     */
    private function foundationOf(SubDecision $subDecision): ?Foundation
    {
        return match (true) {
            $subDecision instanceof Foundation => $subDecision,
            $subDecision instanceof FoundationReference => $subDecision->foundation,
            $subDecision instanceof Discharge => $subDecision->installation->foundation,
            default => null,
        };
    }

    /**
     * The organ a foundation projects to, once the projection of the ledger write behind it is committed.
     *
     * The organ's foundation reference is a composite primary key, which DQL cannot compare as an association;
     * the components are compared individually instead.
     */
    private function organIdOf(Foundation $foundation): ?int
    {
        $rows = $this->emWeb->createQueryBuilder()
            ->select('o.id')
            ->from(
                Organ::class,
                'o',
            )
            ->join(
                'o.foundation',
                'f',
            )
            ->where('f.meeting_type = :meetingType')
            ->andWhere('f.meeting_number = :meetingNumber')
            ->andWhere('f.decision_point = :decisionPoint')
            ->andWhere('f.decision_number = :decisionNumber')
            ->andWhere('f.sequence = :sequence')
            ->setParameter(
                'meetingType',
                $foundation->getMeetingType(),
            )
            ->setParameter(
                'meetingNumber',
                $foundation->getMeetingNumber(),
            )
            ->setParameter(
                'decisionPoint',
                $foundation->getDecisionPoint(),
            )
            ->setParameter(
                'decisionNumber',
                $foundation->getDecisionNumber(),
            )
            ->setParameter(
                'sequence',
                $foundation->sequence,
            )
            ->getQuery()
            ->getScalarResult();

        // An annulled or never-projected foundation has no organ, and there is nothing to sync for it.
        return [] === $rows
            ? null
            : (int) array_values($rows[0])[0];
    }
}
