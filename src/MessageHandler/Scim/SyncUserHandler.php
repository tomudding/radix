<?php

declare(strict_types=1);

namespace App\MessageHandler\Scim;

use App\Entity\User\Enums\ScimLocalTypes;
use App\Entity\User\ScimRemoteLink;
use App\Exception\Scim\ScimException;
use App\Exception\Scim\ScimNotFoundException;
use App\Message\Scim\SyncGroupMembershipMessage;
use App\Message\Scim\SyncUserMessage;
use App\Repository\Decision\OrganMemberRepository;
use App\Repository\User\ScimRemoteLinkRepository;
use App\Repository\User\UserRepository;
use App\Service\Scim\ScimClientInterface;
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
 * Pushes one user's account to the SCIM source. The message carries the id only and the handler
 * loads the state, so a redelivery arrives at the same result as the original. A disabled
 * account syncs with `active: false` and is never deleted.
 */
#[AsMessageHandler]
class SyncUserHandler
{
    public function __construct(
        private readonly ScimClientInterface $client,
        private readonly UserScimMapper $mapper,
        private readonly UserRepository $userRepository,
        private readonly ScimRemoteLinkRepository $linkRepository,
        private readonly OrganMemberRepository $organMemberRepository,
        #[Autowire(service: 'doctrine.orm.web_entity_manager')]
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

        $user = $this->userRepository->find($lidnr);
        if (null === $user) {
            // The account no longer exists. Nothing is removed remotely under the deletion
            // policy, but the membership of every organ of the member may have changed.
            $this->logger->debug(
                'SCIM sync skipped; the account of member {lidnr} no longer exists.',
                ['lidnr' => $lidnr],
            );
            $this->resyncOrganMemberships($lidnr);

            return;
        }

        $member = $user->member;
        $scimUser = $this->mapper->map(
            new UserScimSource(
                lidnr: (string) $member->lidnr,
                firstName: $member->firstName,
                lastName: $member->lastName,
                email: $member->email,
                active: !$member->hidden,
            ),
        );

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
            // The source answered without an id; store logged it. Resyncing the organ
            // memberships would only re-run the sync that cannot be recorded.
            return;
        }

        $this->logger->info(
            'SCIM user {lidnr} is in sync with remote id {remoteId}.',
            [
                'lidnr' => $lidnr,
                'remoteId' => $remoteId,
            ],
        );

        $this->resyncOrganMemberships($lidnr);
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
            // The source returned success without an id; without one the object cannot be
            // addressed on the next sync.
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
                $lidnr,
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
     * With the user synced, the membership of every organ of the member may have needed
     * exactly this link; those are synced again.
     */
    private function resyncOrganMemberships(int $lidnr): void
    {
        foreach ($this->organMemberRepository->findCurrentOrganIds($lidnr) as $organId) {
            $this->messageBus->dispatch(new SyncGroupMembershipMessage($organId));
        }
    }
}
