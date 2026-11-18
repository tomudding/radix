<?php

declare(strict_types=1);

namespace App\Repository\User;

use App\Entity\User\Enums\ScimLocalTypes;
use App\Entity\User\ScimRemoteLink;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

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
        return $this->findFor(
            ScimLocalTypes::User,
            $lidnr,
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

    public function findForGroup(int $organId): ?ScimRemoteLink
    {
        return $this->findFor(
            ScimLocalTypes::Group,
            $organId,
        );
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
                $lidnrs,
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
        int $localId,
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
