<?php

declare(strict_types=1);

namespace App\MessageHandler\Scim;

use App\Entity\User\Enums\ScimLocalTypes;
use App\Entity\User\ScimRemoteLink;
use App\Exception\Scim\ScimException;
use App\Exception\Scim\ScimNotFoundException;
use App\Message\Scim\SyncGroupMembershipMessage;
use App\Message\Scim\SyncUserMessage;
use App\Repository\User\ScimRemoteLinkRepository;
use App\Service\Scim\GroupScimMapper;
use App\Service\Scim\GroupScimSource;
use App\Service\Scim\ResolvedScimGroup;
use App\Service\Scim\ScimClientInterface;
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
 * Brings the membership of one group on the SCIM source in line with the local state. The patch
 * is computed against what the source reports, so a redelivery patches the delta only. As in the
 * full group sync, a member without a remote link is never sent as a local id: a sync of that
 * user is dispatched instead.
 */
#[AsMessageHandler]
class SyncGroupMembershipHandler
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

    public function __invoke(SyncGroupMembershipMessage $message): void
    {
        if (!$this->scimEnabled) {
            $this->logger->debug(
                'SCIM is disabled; the membership of {localType}:{localId} is not synced.',
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
                'SCIM membership sync skipped; {localType} {localId} no longer exists.',
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

        $link = $this->linkRepository->findByLocal(
            $message->getLocalType(),
            $message->getLocalId(),
        );

        $current = null;
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
                    $message,
                    $link,
                    $exception,
                );

                throw $exception;
            }
        }

        if (null === $link) {
            try {
                $remoteId = $this->client->createGroup(
                    $this->mapper->map(
                        new GroupScimSource(
                            displayName: $group->displayName,
                            externalId: $group->externalId,
                            memberRemoteIds: $memberRemoteIds,
                        ),
                    ),
                )->remoteId;
            } catch (ScimException $exception) {
                $this->fail(
                    $message,
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
                        $message,
                        $link,
                        $exception,
                    );

                    throw $exception;
                }
            }

            $remoteId = $link->remoteId;
        }

        $this->storeMembership(
            $message->getLocalType(),
            $message->getLocalId(),
            $remoteId,
            $group,
            $memberRemoteIds,
        );
        $this->logger->info(
            'SCIM membership of {localType}:{localId} is in sync with remote id {remoteId}.',
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

    /**
     * @param list<string> $memberRemoteIds
     */
    private function storeMembership(
        ScimLocalTypes $localType,
        string $localId,
        ?string $remoteId,
        ResolvedScimGroup $group,
        array $memberRemoteIds,
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

        $link->payloadHash = $this->mapper->map(
            new GroupScimSource(
                displayName: $group->displayName,
                externalId: $group->externalId,
                memberRemoteIds: $memberRemoteIds,
            ),
        )->payloadHash();
        $link->lastSyncedAt = new DateTimeImmutable();
        $link->lastError = null;
        $this->entityManager->flush();
    }

    private function fail(
        SyncGroupMembershipMessage $message,
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
            'SCIM membership sync failed for {localType}:{localId} (remote id {remoteId}): {reason}',
            [
                'localType' => $message->getLocalType()->value,
                'localId' => $message->getLocalId(),
                'remoteId' => $link?->remoteId,
                'reason' => $exception->getMessage(),
            ],
        );
    }
}
