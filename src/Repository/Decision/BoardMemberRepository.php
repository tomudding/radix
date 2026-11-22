<?php

declare(strict_types=1);

namespace App\Repository\Decision;

use App\Entity\Decision\AssociationYear;
use App\Entity\Decision\BoardMember;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;
use SortDirection;

use function array_map;
use function array_unique;
use function array_values;
use function sort;

/**
 * @extends ServiceEntityRepository<BoardMember>
 */
class BoardMemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct(
            $registry,
            BoardMember::class,
        );
    }

    /**
     * The members of the board whose term began in the given association year, as the SCIM board
     * group for that year. A board starts with the association year (July 1st), so the term's year
     * is the installation date's association year.
     *
     * @return list<int>
     */
    public function findMembersOfAssociationYear(int $firstYear): array
    {
        $year = AssociationYear::fromYear($firstYear);

        $qb = $this->createQueryBuilder('bm')
            ->select('IDENTITY(bm.member)')
            ->distinct()
            ->where('bm.installDate >= :start')
            ->andWhere('bm.installDate < :end')
            ->setParameter(
                'start',
                $year->getStartDate(),
                Types::DATETIME_IMMUTABLE,
            )
            ->setParameter(
                'end',
                $year->getEndDate()->modify('+1 day'),
                Types::DATETIME_IMMUTABLE,
            );

        return array_values(array_map(
            static fn (array $row): int => (int) array_values($row)[0],
            $qb->getQuery()->getScalarResult(),
        ));
    }

    /**
     * The members of the board in office on a date: installed and not discharged by it. This is
     * what stack on July 1st as the new board is installed and the old one discharged.
     *
     * @return list<int>
     */
    public function findMembersByDate(DateTimeImmutable $date): array
    {
        $qb = $this->createQueryBuilder('bm')
            ->select('IDENTITY(bm.member)')
            ->distinct()
            ->where('bm.installDate <= :date')
            ->setParameter(
                'date',
                $date,
                Types::DATETIME_IMMUTABLE,
            );
        $qb->andWhere($qb->expr()->orX(
            $qb->expr()->isNull('bm.dischargeDate'),
            $qb->expr()->gte(
                'bm.dischargeDate',
                ':date',
            ),
        ));

        return array_values(array_map(
            static fn (array $row): int => (int) array_values($row)[0],
            $qb->getQuery()->getScalarResult(),
        ));
    }

    /**
     * The first years of every association year a board term began in, oldest first. This is what
     * the reconciliation enumerates the per-year BOARD_<n> groups from, so each board that ever
     * existed gets a group even when the current drop has already moved on.
     *
     * @return list<int>
     */
    public function findInstallationYears(): array
    {
        $dates = $this->createQueryBuilder('bm')
            ->select('bm.installDate')
            ->distinct()
            ->getQuery()
            ->getScalarResult();

        $years = array_map(
            static fn (array $row): int => AssociationYear::fromDate(
                $row['installDate'] instanceof DateTimeImmutable
                    ? $row['installDate']
                    : new DateTimeImmutable((string) $row['installDate']),
            )->getYear(),
            $dates,
        );

        sort($years);

        return array_values(array_unique($years));
    }

    /**
     * @return Paginator<BoardMember>
     */
    public function paginateBoardMembers(
        bool $includeFormer,
        bool $includeDeleted,
        int $page,
        int $pageSize,
    ): Paginator {
        $qb = $this->createQueryBuilder('bm');
        $qb->innerJoin(
            'bm.member',
            'm',
        )
            ->addSelect('m');

        if (!$includeFormer) {
            $qb->andWhere('bm.installDate <= CURRENT_TIMESTAMP()')
                ->andWhere($qb->expr()->orX(
                    $qb->expr()->isNull('bm.releaseDate'),
                    $qb->expr()->gt(
                        'bm.releaseDate',
                        'CURRENT_TIMESTAMP()',
                    ),
                ))
                ->andWhere($qb->expr()->orX(
                    $qb->expr()->isNull('bm.dischargeDate'),
                    $qb->expr()->gt(
                        'bm.dischargeDate',
                        'CURRENT_TIMESTAMP()',
                    ),
                ));
        }

        if (!$includeDeleted) {
            $qb->andWhere('m.deleted = false');
        }

        $qb->orderBy(
            'bm.installDate',
            SortDirection::Descending,
        )
            ->addOrderBy(
                'm.lidnr',
                SortDirection::Ascending,
            )
            ->addOrderBy(
                'bm.id',
                SortDirection::Ascending,
            )
            ->setFirstResult(($page - 1) * $pageSize)
            ->setMaxResults($pageSize);

        return new Paginator(
            $qb->getQuery(),
            false,
        );
    }
}
