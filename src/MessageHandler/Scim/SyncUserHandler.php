<?php

declare(strict_types=1);

namespace App\MessageHandler\Scim;

use App\Entity\User\Enums\ScimLocalTypes;
use App\Entity\User\ScimRemoteLink;
use App\Exception\Scim\ScimException;
use App\Exception\Scim\ScimNotFoundException;
use App\Message\Scim\SyncGroupMembershipMessage;
use App\Message\Scim\SyncUserMessage;
use App\Repository\Decision\MemberRepository;
use App\Repository\User\ScimRemoteLinkRepository;
use App\Service\Scim\ScimClientInterface;
use App\Service\Scim\ScimGroupResolver;
use App\Service\Scim\ScimOperation;
use App\Service\Scim\ScimUser;
use App\Service\Scim\UserScimMapper;
use App\Service\Scim\UserScimSource;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Pushes one member's account to the SCIM source. The message carries the id only and the handler
 * loads the state, so a redelivery arrives at the same result as the original. The register's
 * members are the source of truth, not only the ones with a website account. A member who is
 * deleted or lapsed is synced inactive and removed from their groups; a member the projection no
 * longer holds is deactivated remotely, never deleted. The memberships are resynced too, because
 * those groups may finally be able to resolve a member once a missing link exists.
 */
#[AsMessageHandler]
class SyncUserHandler
{
    public function __construct(
        private readonly ScimClientInterface $client,
        private readonly UserScimMapper $mapper,
        private readonly MemberRepository $memberRepository,
        private readonly ScimRemoteLinkRepository $linkRepository,
        private readonly ScimGroupResolver $groupResolver,
        #[Autowire(service: 'doctrine.orm.application_entity_manager')]
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
        private readonly bool $scimEnabled,
    ) {
    }

    public function __invoke(SyncUserMessage $message): void
    {
        $lidnr = $message->getLidnr();

        if (!$this->scimEnabled) {
            $this->logger->debug(
                'SCIM is disabled; the account of member {lidnr} is not synced.',
                ['lidnr' => $lidnr],
            );

            return;
        }

        $member = $this->memberRepository->find($lidnr);
        if (null === $member) {
            // The member no longer exists. Nothing is removed remotely, but the account is
            // deactivated so the old identity cannot keep logging in; the link stays so a later
            // member with the same number can adopt the deactivated account.
            $this->logger->debug(
                'SCIM sync deactivates member {lidnr}; they are no longer in the register.',
                ['lidnr' => $lidnr],
            );
            $this->deactivate($lidnr);

            return;
        }

        $scimUser = $this->mapper->map(UserScimSource::fromMember($member));

        $hash = $scimUser->payloadHash();
        $link = $this->linkRepository->findForUser($lidnr);
        if (
            null !== $link
            && $hash === $link->payloadHash
        ) {
            return;
        }

        try {
            $remoteId = $this->sync(
                $link,
                $scimUser,
            );
        } catch (ScimException $exception) {
            $this->fail(
                $lidnr,
                $link,
                $exception,
            );

            throw $exception;
        }

        if (
            !$this->store(
                $lidnr,
                $remoteId,
                $hash,
            )
        ) {
            return;
        }

        $this->logger->info(
            'SCIM user {lidnr} is in sync with remote id {remoteId}.',
            [
                'lidnr' => $lidnr,
                'remoteId' => $remoteId,
            ],
        );

        $this->resyncGroupMemberships($lidnr);
    }

    /**
     * Deactivates the remote account of a member the register no longer holds. The link is kept
     * and marked with the deactivated payload, so the nightly reconciliation can see that this
     * member was deliberately disabled and does not dispatch the same patch every night.
     */
    private function deactivate(int $lidnr): void
    {
        $link = $this->linkRepository->findForUser($lidnr);
        if (null === $link) {
            return;
        }

        $deactivated = $this->mapper->deactivated($lidnr);
        try {
            $this->client->patchUser(
                $link->remoteId,
                [
                    ScimOperation::replace(
                        'active',
                        false,
                    ),
                ],
            );
        } catch (ScimNotFoundException) {
            // The remote account is gone; there is nothing left to deactivate.
            $this->entityManager->remove($link);
            $this->entityManager->flush();

            return;
        } catch (ScimException $exception) {
            $this->fail(
                $lidnr,
                $link,
                $exception,
            );

            throw $exception;
        }

        $link->payloadHash = $deactivated->payloadHash();
        $link->lastSyncedAt = new DateTimeImmutable();
        $link->lastError = null;
        $this->entityManager->flush();

        $this->logger->info(
            'SCIM user {lidnr} is deactivated; the member is no longer in the register.',
            ['lidnr' => $lidnr],
        );
    }

    private function sync(
        ?ScimRemoteLink $link,
        ScimUser $scimUser,
    ): ?string {
        if (null === $link) {
            return $this->client->createUser($scimUser)->remoteId;
        }

        try {
            return $this->client->updateUser(
                $link->remoteId,
                $scimUser,
            )->remoteId;
        } catch (ScimNotFoundException) {
            // The remote object is gone; the link is stale. Drop it, and the create adopts
            // whatever the source still manages for this user name.
            $this->entityManager->remove($link);
            $this->entityManager->flush();

            return $this->client->createUser($scimUser)->remoteId;
        }
    }

    private function store(
        int $lidnr,
        ?string $remoteId,
        string $hash,
    ): bool {
        if (null === $remoteId) {
            $this->logger->error(
                'The SCIM source returned success without a remote id for member {lidnr}.',
                ['lidnr' => $lidnr],
            );

            return false;
        }

        $link = $this->linkRepository->findForUser($lidnr);
        if (null === $link) {
            $link = new ScimRemoteLink(
                ScimLocalTypes::User,
                (string) $lidnr,
                $remoteId,
            );
            $this->entityManager->persist($link);
        } else {
            $link->remoteId = $remoteId;
        }

        $link->payloadHash = $hash;
        $link->lastSyncedAt = new DateTimeImmutable();
        $link->lastError = null;
        $this->entityManager->flush();

        return true;
    }

    private function fail(
        int $lidnr,
        ?ScimRemoteLink $link,
        ScimException $exception,
    ): void {
        if (
            null !== $link
            && $this->entityManager->contains($link)
        ) {
            $link->lastError = $exception->getMessage();
            $this->entityManager->flush();
        }

        $this->logger->warning(
            'SCIM user sync failed for member {lidnr} (remote id {remoteId}): {reason}',
            [
                'lidnr' => $lidnr,
                'remoteId' => $link?->remoteId,
                'reason' => $exception->getMessage(),
            ],
        );
    }

    /**
     * With the user synced, the membership of every group of the member may have needed exactly
     * this link; those are synced again.
     */
    private function resyncGroupMemberships(int $lidnr): void
    {
        foreach ($this->groupResolver->refsForMember($lidnr) as $ref) {
            $this->messageBus->dispatch(new SyncGroupMembershipMessage(
                $ref->localType,
                $ref->localId,
            ));
        }
    }
}
