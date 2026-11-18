<?php

declare(strict_types=1);

namespace App\EventListener\Decision;

use App\Entity\Database\Address;
use App\Entity\Database\Decision;
use App\Entity\Database\MailingList;
use App\Entity\Database\MailingListMember;
use App\Entity\Database\Meeting;
use App\Entity\Database\Member;
use App\Service\Decision\MeetingService;
use App\Service\Decision\MemberService;
use App\Service\Decision\MiscService;
use App\Service\Decision\ProjectionState;
use App\Service\Scim\ScimSyncDispatcher;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Events;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Takes out of the projection what is taken out of the ledger, before the removal: what has to
 * be removed is determined from the entity while its relations are still readable.
 */
#[AsDoctrineListener(
    event: Events::preRemove,
    connection: 'default',
)]
final class DatabaseDeletionListener
{
    public function __construct(
        private readonly ProjectionState $state,
        private readonly MeetingService $meetingService,
        private readonly MemberService $memberService,
        private readonly MiscService $miscService,
        #[Autowire(service: 'doctrine.orm.web_entity_manager')]
        private readonly EntityManagerInterface $emWeb,
        private readonly ScimSyncDispatcher $scimSync,
    ) {
    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        if (!$this->state->isEnabled()) {
            return;
        }

        $entity = $args->getObject();
        $scimRemovals = [];

        switch (true) {
            case $entity instanceof Address:
                $this->memberService->deleteAddress($entity);
                break;

            case $entity instanceof Member:
                // Only a member the projection is free to drop arrives here; a member any decision still names is
                // stripped of their data rather than deleted. The organs are read before the deletion and the
                // dispatch waits for the flush below, because a message a worker consumes before that commit reads
                // the member as still current and changes nothing.
                $scimRemovals[] = [
                    $entity->lidnr,
                    $this->scimSync->currentOrganIds($entity->lidnr),
                ];
                $this->memberService->deleteMember($entity);
                break;

            case $entity instanceof Meeting:
                // Nothing removes a meeting, and what removing one should do to the decisions projected from it has
                // never been decided, so it is refused rather than half-done.
                throw new RuntimeException('Deleting a meeting from the projection is not implemented');

            case $entity instanceof Decision:
                $this->meetingService->deleteDecision($entity);
                break;

            case $entity instanceof MailingList:
                $this->miscService->deleteList($entity);
                break;

            case $entity instanceof MailingListMember:
                $this->miscService->deleteListMembership($entity);
                break;

            default:
                return;
        }

        $this->emWeb->flush();

        foreach ($scimRemovals as [$lidnr, $organIds]) {
            $this->scimSync->memberRemoved(
                $lidnr,
                $organIds,
            );
        }
    }
}
