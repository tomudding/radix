<?php

declare(strict_types=1);

namespace App\Repository\Decision;

use App\Entity\Decision\OrganMember;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;
use SortDirection;

use function array_map;
use function array_values;

/**
 * @extends ServiceEntityRepository<OrganMember>
 */
class OrganMemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct(
            $registry,
            OrganMember::class,
        );
    }

    /**
     * @return Paginator<OrganMember>
     */
    public function paginateByBody(
        int $body,
        bool $includeDischarged,
        bool $includeDeleted,
        int $page,
        int $pageSize,
    ): Paginator {
        $qb = $this->createQueryBuilder('om');

        $qb->addSelect('m')
            ->innerJoin(
                'om.member',
                'm',
            )
            ->where('om.organ = :body')
            ->setParameter(
                'body',
                $body,
            );

        if (!$includeDischarged) {
            $this->onlyCurrent($qb);
        }

        if (!$includeDeleted) {
            $qb->andWhere('m.deleted = false');
        }

        $qb->orderBy(
            'm.lidnr',
            SortDirection::Ascending,
        )
            ->addOrderBy(
                'om.id',
                SortDirection::Ascending,
            )
            ->setFirstResult(($page - 1) * $pageSize)
            ->setMaxResults($pageSize);

        return new Paginator(
            $qb->getQuery(),
            false,
        );
    }

    /**
     * @return Paginator<OrganMember>
     */
    public function paginateByMember(
        int $lidnr,
        bool $includeDischarged,
        int $page,
        int $pageSize,
    ): Paginator {
        $qb = $this->createQueryBuilder('om');

        // `organInformation` is an inverse-side one-to-one, which Doctrine cannot proxy: without the join each
        // distinct body on the page costs one extra SELECT.
        $qb->addSelect('o')
            ->innerJoin(
                'om.organ',
                'o',
            )
            ->addSelect('oi')
            ->leftJoin(
                'o.organInformation',
                'oi',
            )
            ->where('om.member = :lidnr')
            ->setParameter(
                'lidnr',
                $lidnr,
            );

        if (!$includeDischarged) {
            $this->onlyCurrent($qb);
        }

        $qb->orderBy(
            'o.abbr',
            SortDirection::Ascending,
        )
            ->addOrderBy(
                'om.id',
                SortDirection::Ascending,
            )
            ->setFirstResult(($page - 1) * $pageSize)
            ->setMaxResults($pageSize);

        return new Paginator(
            $qb->getQuery(),
            false,
        );
    }

    /**
     * The organs in which the member is currently installed.
     *
     * @return list<int>
     */
    public function findCurrentOrganIds(int $lidnr): array
    {
        $qb = $this->createQueryBuilder('om')
            ->select('IDENTITY(om.organ)')
            ->distinct()
            ->andWhere('om.member = :member')
            ->setParameter(
                'member',
                $lidnr,
            );
        $this->onlyCurrent($qb);

        return array_values(array_map(
            static fn (array $row): int => (int) array_values($row)[0],
            $qb->getQuery()->getScalarResult(),
        ));
    }

    /**
     * The members currently installed in the organ.
     *
     * @return list<int>
     */
    public function findCurrentMemberLidnrs(int $organId): array
    {
        $qb = $this->createQueryBuilder('om')
            ->select('IDENTITY(om.member)')
            ->distinct()
            ->andWhere('om.organ = :organ')
            ->setParameter(
                'organ',
                $organId,
            );
        $this->onlyCurrent($qb);

        return array_values(array_map(
            static fn (array $row): int => (int) array_values($row)[0],
            $qb->getQuery()->getScalarResult(),
        ));
    }

    /**
     * The members of the organ a date falls on: installed before it and not discharged by it. For
     * an abrogated body this is the membership frozen at the abrogation date, which is what the
     * SCIM group holds once the body is gone.
     *
     * @return list<int>
     */
    public function findMemberLidnrsByDate(
        int $organId,
        DateTimeImmutable $date,
    ): array {
        $qb = $this->createQueryBuilder('om')
            ->select('IDENTITY(om.member)')
            ->distinct()
            ->andWhere('om.organ = :organ')
            ->andWhere('om.installDate <= :date')
            ->setParameter(
                'organ',
                $organId,
            )
            ->setParameter(
                'date',
                $date,
                Types::DATETIME_IMMUTABLE,
            );
        $qb->andWhere($qb->expr()->orX(
            $qb->expr()->isNull('om.dischargeDate'),
            $qb->expr()->gte(
                'om.dischargeDate',
                ':date',
            ),
        ));

        return array_values(array_map(
            static fn (array $row): int => (int) array_values($row)[0],
            $qb->getQuery()->getScalarResult(),
        ));
    }

    private function onlyCurrent(QueryBuilder $qb): void
    {
        $qb->andWhere('om.installDate <= :now')
            ->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('om.dischargeDate'),
                $qb->expr()->gte(
                    'om.dischargeDate',
                    ':now',
                ),
            ))
            ->setParameter(
                'now',
                new DateTimeImmutable(),
                Types::DATETIME_IMMUTABLE,
            );
    }
}
