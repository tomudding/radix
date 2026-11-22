<?php

declare(strict_types=1);

namespace App\Repository\User;

use App\Entity\User\Enums\ScimLocalTypes;
use App\Entity\User\ScimRemoteLink;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

use function array_map;
use function array_values;
use function count;

/**
 * @extends ServiceEntityRepository<ScimRemoteLink>
 */
class ScimRemoteLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct(
            $registry,
            ScimRemoteLink::class,
        );
    }

    public function findForUser(int $lidnr): ?ScimRemoteLink
    {
        return $this->findByLocal(
            ScimLocalTypes::User,
            (string) $lidnr,
        );
    }

    /**
     * The link of any local object by its kind and identifier.
     */
    public function findByLocal(
        ScimLocalTypes $localType,
        string $localId,
    ): ?ScimRemoteLink {
        return $this->findFor(
            $localType,
            $localId,
        );
    }

    /**
     * Drops links whose remote object is gone and flushes. Without the drop, the sync handler
     * sees a link and an unchanged hash, skips the object, and the vanished remote object is
     * never recreated; with it, the dispatched sync takes the create path.
     *
     * @param list<ScimRemoteLink> $links
     */
    public function drop(array $links): void
    {
        if ([] === $links) {
            return;
        }

        $entityManager = $this->getEntityManager();
        foreach ($links as $link) {
            $entityManager->remove($link);
        }

        $entityManager->flush();
    }

    /**
     * The link counts per local kind, for the administration's sync page. A link exists once the
     * remote object is known, so this is also how many objects the source manages on our behalf.
     *
     * @return array<string, int> keyed by the {@see ScimLocalTypes} value; only the kinds that
     * have at least one link
     */
    public function countByType(): array
    {
        $rows = $this->createQueryBuilder('l')
            ->select(
                'l.localType AS type',
                'COUNT(l.id) AS total',
            )
            ->groupBy('l.localType')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['type']->value] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * The links whose last sync failed, newest first, capped so a source that is down does not
     * render an unbounded list. The error text is the runbook's starting point.
     *
     * @return list<ScimRemoteLink>
     */
    public function findFailing(int $limit): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.lastError IS NOT NULL')
            ->orderBy(
                'l.lastSyncedAt',
                'DESC',
            )
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * The links the source manages for objects the sync never reached: the handler records a
     * successful push with {@see ScimRemoteLink::$lastSyncedAt}, so a link without one means a
     * push was dispatched but never completed.
     */
    public function countNeverSynced(): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('l.lastSyncedAt IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The timestamp of the newest successful push, or null when nothing has ever synced.
     */
    public function lastSyncedAt(): ?DateTimeImmutable
    {
        $row = $this->createQueryBuilder('l')
            ->select('MAX(l.lastSyncedAt) AS lastSyncedAt')
            ->getQuery()
            ->getSingleResult();

        // An aggregate loses the column's type mapping and comes back as a string.
        if (null === $row['lastSyncedAt']) {
            return null;
        }

        return new DateTimeImmutable($row['lastSyncedAt']);
    }

    /**
     * The remote identifiers of the given members in one query, so a group handler does not pay a
     * lookup per member.
     *
     * @param list<int> $lidnrs
     *
     * @return array<int, string> lidnr to remote identifier
     */
    public function findUserRemoteIds(array $lidnrs): array
    {
        if (0 === count($lidnrs)) {
            return [];
        }

        $rows = $this->createQueryBuilder('l')
            ->select(
                'l.localId',
                'l.remoteId',
            )
            ->where('l.localType = :type')
            ->andWhere('l.localId IN (:lidnrs)')
            ->setParameter(
                'type',
                ScimLocalTypes::User,
            )
            ->setParameter(
                'lidnrs',
                array_values(array_map(
                    static fn (int $lidnr): string => (string) $lidnr,
                    $lidnrs,
                )),
            )
            ->getQuery()
            ->getResult();

        $remoteIds = [];
        foreach ($rows as $row) {
            $remoteIds[(int) $row['localId']] = (string) $row['remoteId'];
        }

        return $remoteIds;
    }

    private function findFor(
        ScimLocalTypes $localType,
        string $localId,
    ): ?ScimRemoteLink {
        return $this->createQueryBuilder('l')
            ->where('l.localType = :type')
            ->andWhere('l.localId = :localId')
            ->setParameter(
                'type',
                $localType,
            )
            ->setParameter(
                'localId',
                $localId,
            )
            ->getQuery()
            ->getOneOrNullResult();
    }
}
