<?php

declare(strict_types=1);

namespace App\MessageHandler\Scim;

use App\Entity\Decision\Organ;
use App\Entity\User\Enums\ScimLocalTypes;
use App\Entity\User\ScimRemoteLink;
use App\Exception\Scim\ScimException;
use App\Exception\Scim\ScimNotFoundException;
use App\Message\Scim\SyncGroupMessage;
use App\Message\Scim\SyncUserMessage;
use App\Repository\Decision\OrganMemberRepository;
use App\Repository\Decision\OrganRepository;
use App\Repository\User\ScimRemoteLinkRepository;
use App\Service\Scim\GroupScimMapper;
use App\Service\Scim\GroupScimSource;
use App\Service\Scim\ScimClientInterface;
use App\Service\Scim\ScimGroup;
use App\Service\Scim\ScimGroupKey;
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
 * carries the id only, so a redelivery arrives at the same result as the original. A member
 * without a remote link is never sent as a local id: a sync of that user is dispatched instead,
 * and that handler syncs this organ's membership back once the link exists.
 */
#[AsMessageHandler]
class SyncGroupHandler
{
    public function __construct(
        private readonly ScimClientInterface $client,
        private readonly GroupScimMapper $mapper,
        private readonly OrganRepository $organRepository,
        private readonly OrganMemberRepository $organMemberRepository,
        private readonly ScimRemoteLinkRepository $linkRepository,
        #[Autowire(service: 'doctrine.orm.web_entity_manager')]
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
        private readonly bool $scimEnabled,
    ) {
    }

    public function __invoke(SyncGroupMessage $message): void
    {
        $organId = $message->getOrganId();

        if (!$this->scimEnabled) {
            $this->logger->debug(
                'SCIM is disabled; organ {organId} is not synced.',
                ['organId' => $organId],
            );

            return;
        }

        $organ = $this->organRepository->findOrgan($organId);
        if (null === $organ) {
            $this->logger->debug(
                'SCIM group sync skipped; organ {organId} no longer exists.',
                ['organId' => $organId],
            );

            return;
        }

        $sameName = $this->organRepository->findActiveByNameAndType(
            $organ->type,
            $organ->name,
        );
        if (count($sameName) > 1) {
            // Two active organs of one type with the same name derive the same group key, and
            // each sync would overwrite the other's membership. The projection must not be in
            // that state, so the organ is refused and reported rather than synced.
            $this->logger->warning(
                'SCIM group sync refused for organ {organId}: {count} active organs share its type and name.',
                [
                    'organId' => $organId,
                    'count' => count($sameName),
                ],
            );

            return;
        }

        $memberRemoteIds = $this->currentMembership($organId);
        if (null === $memberRemoteIds) {
            return;
        }

        $scimGroup = $this->buildGroup(
            $organ,
            $memberRemoteIds,
        );
        $hash = $scimGroup->payloadHash();
        $link = $this->linkRepository->findForGroup($organId);
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
                $organId,
                $link,
                $exception,
            );

            throw $exception;
        }

        $this->store(
            $organId,
            $remoteId,
            $hash,
        );
        $this->logger->info(
            'SCIM group for organ {organId} is in sync with remote id {remoteId}.',
            [
                'organId' => $organId,
                'remoteId' => $remoteId,
            ],
        );
    }

    /**
     * @return list<string>|null The remote ids of the organ's current members, or null when a
     * member has no link yet and a sync of that user has been dispatched instead
     */
    private function currentMembership(int $organId): ?array
    {
        $lidnrs = $this->organMemberRepository->findCurrentMemberLidnrs($organId);
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

    /**
     * @param list<string> $memberRemoteIds
     */
    private function buildGroup(
        Organ $organ,
        array $memberRemoteIds,
    ): ScimGroup {
        return $this->mapper->map(
            new GroupScimSource(
                displayName: $organ->name,
                externalId: ScimGroupKey::for($organ),
                memberRemoteIds: $memberRemoteIds,
            ),
        );
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
        int $organId,
        ?string $remoteId,
        string $hash,
    ): void {
        if (null === $remoteId) {
            // The source returned success without an id; without one the group cannot be
            // addressed on the next sync.
            $this->logger->error(
                'The SCIM source returned success without a remote id for organ {organId}.',
                ['organId' => $organId],
            );

            return;
        }

        $link = $this->linkRepository->findForGroup($organId);
        if (null === $link) {
            $link = new ScimRemoteLink(
                ScimLocalTypes::Group,
                $organId,
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
        int $organId,
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
            'SCIM group sync failed for organ {organId} (remote id {remoteId}): {reason}',
            [
                'organId' => $organId,
                'remoteId' => $link?->remoteId,
                'reason' => $exception->getMessage(),
            ],
        );
    }
}
