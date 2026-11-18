<?php

declare(strict_types=1);

namespace App\MessageHandler\Scim;

use App\Entity\Decision\Organ;
use App\Entity\User\Enums\ScimLocalTypes;
use App\Entity\User\ScimRemoteLink;
use App\Exception\Scim\ScimException;
use App\Exception\Scim\ScimNotFoundException;
use App\Message\Scim\SyncGroupMembershipMessage;
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
 * Brings the membership of one group on the SCIM source in line with the local state. The patch
 * is computed against what the source reports, so a redelivery patches the delta only. As in the
 * group sync, a member without a remote link is never sent as a local id: a sync of that user is
 * dispatched instead.
 */
#[AsMessageHandler]
class SyncGroupMembershipHandler
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

    public function __invoke(SyncGroupMembershipMessage $message): void
    {
        $organId = $message->getOrganId();

        if (!$this->scimEnabled) {
            $this->logger->debug(
                'SCIM is disabled; the membership of organ {organId} is not synced.',
                ['organId' => $organId],
            );

            return;
        }

        $organ = $this->organRepository->findOrgan($organId);
        if (null === $organ) {
            $this->logger->debug(
                'SCIM membership sync skipped; organ {organId} no longer exists.',
                ['organId' => $organId],
            );

            return;
        }

        $memberRemoteIds = $this->resolvableMembership($organId);
        if (null === $memberRemoteIds) {
            return;
        }

        $scimGroup = $this->buildGroup(
            $organ,
            $memberRemoteIds,
        );
        $link = $this->linkRepository->findForGroup($organId);

        if (null !== $link) {
            try {
                $current = $this->client->patchGroupMembers(
                    $link->remoteId,
                    [],
                    [],
                );
            } catch (ScimNotFoundException) {
                // The remote group is gone; the link is stale. Drop it and recreate below.
                $this->entityManager->remove($link);
                $this->entityManager->flush();
                $link = null;
            } catch (ScimException $exception) {
                $this->fail(
                    $organId,
                    $link,
                    $exception,
                );

                throw $exception;
            }
        }

        if (null === $link) {
            try {
                $remoteId = $this->client->createGroup($scimGroup)->remoteId;
            } catch (ScimException $exception) {
                $this->fail(
                    $organId,
                    null,
                    $exception,
                );

                throw $exception;
            }
        } else {
            $add = array_values(array_diff($memberRemoteIds, $current->memberRemoteIds));
            $remove = array_values(array_diff($current->memberRemoteIds, $memberRemoteIds));
            if (
                [] !== $add
                || [] !== $remove
            ) {
                try {
                    $this->client->patchGroupMembers(
                        $link->remoteId,
                        $add,
                        $remove,
                    );
                } catch (ScimException $exception) {
                    $this->fail(
                        $organId,
                        $link,
                        $exception,
                    );

                    throw $exception;
                }
            }

            $remoteId = $link->remoteId;
        }

        $this->store(
            $organId,
            $remoteId,
            $scimGroup,
        );
        $this->logger->info(
            'SCIM membership of organ {organId} is in sync with remote id {remoteId}.',
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
    private function resolvableMembership(int $organId): ?array
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

    private function store(
        int $organId,
        ?string $remoteId,
        ScimGroup $scimGroup,
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

        $link->payloadHash = $scimGroup->payloadHash();
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
            'SCIM membership sync failed for organ {organId} (remote id {remoteId}): {reason}',
            [
                'organId' => $organId,
                'remoteId' => $link?->remoteId,
                'reason' => $exception->getMessage(),
            ],
        );
    }
}
