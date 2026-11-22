<?php

declare(strict_types=1);

namespace App\MessageHandler\Scim;

use App\Entity\User\Enums\ScimLocalTypes;
use App\Entity\User\ScimRemoteLink;
use App\Exception\Scim\ScimException;
use App\Exception\Scim\ScimNotFoundException;
use App\Message\Scim\SyncGroupMessage;
use App\Message\Scim\SyncUserMessage;
use App\Repository\User\ScimRemoteLinkRepository;
use App\Service\Scim\GroupScimMapper;
use App\Service\Scim\GroupScimSource;
use App\Service\Scim\ResolvedScimGroup;
use App\Service\Scim\ScimClientInterface;
use App\Service\Scim\ScimGroup;
use App\Service\Scim\ScimGroupResolver;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

use function array_diff;
use function array_keys;
use function array_values;
use function count;

/**
 * Pushes a group's full state (name, identifier, membership) to the SCIM source. The message
 * carries the local kind and identifier only, so a redelivery arrives at the same result as the
 * original. The group is resolved from the projection on each run, which is where the body,
 * board and status drops all come from. A member without a remote link is never sent as a local
 * id: a sync of that user is dispatched instead, and that handler syncs this group's membership
 * back once the link exists.
 */
#[AsMessageHandler]
class SyncGroupHandler
{
    public function __construct(
        private readonly ScimClientInterface $client,
        private readonly GroupScimMapper $mapper,
        private readonly ScimGroupResolver $groupResolver,
        private readonly ScimRemoteLinkRepository $linkRepository,
        #[Autowire(service: 'doctrine.orm.application_entity_manager')]
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
        private readonly bool $scimEnabled,
    ) {
    }

    public function __invoke(SyncGroupMessage $message): void
    {
        if (!$this->scimEnabled) {
            $this->logger->debug(
                'SCIM is disabled; group {localType}:{localId} is not synced.',
                [
                    'localType' => $message->getLocalType()->value,
                    'localId' => $message->getLocalId(),
                ],
            );

            return;
        }

        $group = $this->groupResolver->resolve(
            $message->getLocalType(),
            $message->getLocalId(),
        );
        if (null === $group) {
            $this->logger->debug(
                'SCIM group sync skipped; {localType} {localId} no longer exists.',
                [
                    'localType' => $message->getLocalType()->value,
                    'localId' => $message->getLocalId(),
                ],
            );

            return;
        }

        $memberRemoteIds = $this->resolvableMembership(
            $message->getLocalType(),
            $message->getLocalId(),
            $group,
        );
        if (null === $memberRemoteIds) {
            return;
        }

        $scimGroup = $this->mapper->map(
            new GroupScimSource(
                displayName: $group->displayName,
                externalId: $group->externalId,
                memberRemoteIds: $memberRemoteIds,
            ),
        );
        $hash = $scimGroup->payloadHash();
        $link = $this->linkRepository->findByLocal(
            $message->getLocalType(),
            $message->getLocalId(),
        );
        if (
            null !== $link
            && $hash === $link->payloadHash
        ) {
            return;
        }

        try {
            $remoteId = $this->sync(
                $link,
                $scimGroup,
            );
        } catch (ScimException $exception) {
            $this->fail(
                $message,
                $link,
                $exception,
            );

            throw $exception;
        }

        $this->store(
            $message->getLocalType(),
            $message->getLocalId(),
            $remoteId,
            $hash,
        );
        $this->logger->info(
            'SCIM group {localType}:{localId} is in sync with remote id {remoteId}.',
            [
                'localType' => $message->getLocalType()->value,
                'localId' => $message->getLocalId(),
                'remoteId' => $remoteId,
            ],
        );
    }

    /**
     * @return list<string>|null The remote ids of the group's members, or null when a member has
     * no link yet and a sync of that user has been dispatched instead
     */
    private function resolvableMembership(
        ScimLocalTypes $localType,
        string $localId,
        ResolvedScimGroup $group,
    ): ?array {
        $lidnrs = $group->memberLidnrs;
        if (0 === count($lidnrs)) {
            return [];
        }

        $links = $this->linkRepository->findUserRemoteIds($lidnrs);
        $missing = array_diff(
            $lidnrs,
            array_keys($links),
        );
        if ([] !== $missing) {
            foreach ($missing as $lidnr) {
                $this->messageBus->dispatch(new SyncUserMessage((int) $lidnr));
            }

            return null;
        }

        return array_values($links);
    }

    private function sync(
        ?ScimRemoteLink $link,
        ScimGroup $scimGroup,
    ): ?string {
        if (null === $link) {
            return $this->client->createGroup($scimGroup)->remoteId;
        }

        try {
            return $this->client->updateGroup(
                $link->remoteId,
                $scimGroup,
            )->remoteId;
        } catch (ScimNotFoundException) {
            // The remote group is gone; the link is stale. Drop it, and the create adopts
            // whatever the source still manages for this identifier.
            $this->entityManager->remove($link);
            $this->entityManager->flush();

            return $this->client->createGroup($scimGroup)->remoteId;
        }
    }

    private function store(
        ScimLocalTypes $localType,
        string $localId,
        ?string $remoteId,
        string $hash,
    ): void {
        if (null === $remoteId) {
            $this->logger->error(
                'The SCIM source returned success without a remote id for {localType}:{localId}.',
                [
                    'localType' => $localType->value,
                    'localId' => $localId,
                ],
            );

            return;
        }

        $link = $this->linkRepository->findByLocal(
            $localType,
            $localId,
        );
        if (null === $link) {
            $link = new ScimRemoteLink(
                $localType,
                $localId,
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
    }

    private function fail(
        SyncGroupMessage $message,
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
            'SCIM group sync failed for {localType}:{localId} (remote id {remoteId}): {reason}',
            [
                'localType' => $message->getLocalType()->value,
                'localId' => $message->getLocalId(),
                'remoteId' => $link?->remoteId,
                'reason' => $exception->getMessage(),
            ],
        );
    }
}
