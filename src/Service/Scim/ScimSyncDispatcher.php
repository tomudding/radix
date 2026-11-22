<?php

declare(strict_types=1);

namespace App\Service\Scim;

use App\Entity\Database\Decision as LedgerDecision;
use App\Entity\Database\SubDecision;
use App\Entity\Database\SubDecision\Annulment;
use App\Entity\Database\SubDecision\Board\Discharge as BoardDischarge;
use App\Entity\Database\SubDecision\Board\Installation as BoardInstallation;
use App\Entity\Database\SubDecision\Board\Release as BoardRelease;
use App\Entity\Database\SubDecision\Discharge;
use App\Entity\Database\SubDecision\Foundation;
use App\Entity\Database\SubDecision\FoundationReference;
use App\Entity\User\Enums\ScimLocalTypes;
use App\Message\Scim\SyncGroupMembershipMessage;
use App\Message\Scim\SyncGroupMessage;
use App\Message\Scim\SyncUserMessage;
use Symfony\Component\Messenger\MessageBusInterface;

use function sprintf;

/**
 * Dispatches the SCIM sync messages for a register write, once the projection change behind it
 * has been committed. Every handler re-reads the state itself, so only identifiers travel.
 *
 * A body write dispatches the body group by its foundation hash; a board write dispatches the
 * board drops the change lands in. Individual per-year board groups are left to the
 * reconciliation, which enumerates every year; a dispatch only needs the current and recent
 * drops to stay current.
 */
final readonly class ScimSyncDispatcher
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private bool $scimEnabled,
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
     * A member has been removed from the register.
     *
     * The user message keeps the account's bookkeeping in line; nothing is deleted from the
     * source, and the membership handlers remove the member from whatever the source still lists
     * once the projection no longer holds the member.
     */
    public function memberRemoved(int $lidnr): void
    {
        $this->memberChanged($lidnr);
    }

    /**
     * A subdecision has been written to the register; sync the bodies and boards its projection
     * touches.
     */
    public function subDecisionChanged(SubDecision $subDecision): void
    {
        if (!$this->scimEnabled) {
            return;
        }

        foreach ($this->affectedFoundations($subDecision) as $foundation) {
            $hash = $this->projectionHash($foundation);
            $this->messageBus->dispatch(new SyncGroupMessage(
                ScimLocalTypes::Body,
                $hash,
            ));
            $this->messageBus->dispatch(new SyncGroupMembershipMessage(
                ScimLocalTypes::Body,
                $hash,
            ));
        }

        if (!$this->touchesBoard($subDecision)) {
            return;
        }

        foreach (
            [
                ScimGroupResolver::BOARD_CURRENT,
                ScimGroupResolver::BOARD_RECENT,
            ] as $boardKey
        ) {
            $this->messageBus->dispatch(new SyncGroupMessage(
                ScimLocalTypes::Board,
                $boardKey,
            ));
            $this->messageBus->dispatch(new SyncGroupMembershipMessage(
                ScimLocalTypes::Board,
                $boardKey,
            ));
        }
    }

    private function touchesBoard(SubDecision $subDecision): bool
    {
        return $subDecision instanceof BoardInstallation
            || $subDecision instanceof BoardDischarge
            || $subDecision instanceof BoardRelease
            || $subDecision instanceof Annulment;
    }

    /**
     * The foundations a subdecision's projection touches.
     *
     * An annulment reverts the projection of its target decision, so the touched foundations are
     * those of the target decision's subdecisions; an annulment of an annulment reverts them
     * again, hence the recursion.
     *
     * @return list<Foundation>
     */
    private function affectedFoundations(SubDecision $subDecision): array
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
     * The projection's form of a foundation hash. The ledger and the projection render the same
     * composite key differently (space-separated vs hyphenated), and the resolver looks bodies up
     * by the projection's form, so the dispatcher must match it exactly.
     */
    private function projectionHash(Foundation $foundation): string
    {
        return sprintf(
            '%s-%d.%d.%d.%d',
            $foundation->getMeetingType()->value,
            $foundation->getMeetingNumber(),
            $foundation->getDecisionPoint(),
            $foundation->getDecisionNumber(),
            $foundation->sequence,
        );
    }
}
