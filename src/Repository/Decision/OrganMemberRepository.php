<?php

declare(strict_types=1);

namespace App\Repository\Decision;

use App\Entity\Database\Enums\InstallationFunctions;
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
     * The installations of the member that are current, with the function they hold. A deleted
     * member still has these rows, which is what lets a membership sync remove them from the
     * groups they were in.
     *
     * @return list<array{organId: int, function: InstallationFunctions}>
     */
    public function findCurrentFunctionRefsForMember(int $lidnr): array
    {
        $qb = $this->createQueryBuilder('om')
            ->select(
                'IDENTITY(om.organ) AS organId',
                'om.function AS function',
            )
            ->andWhere('om.member = :member')
            ->setParameter(
                'member',
                $lidnr,
            );
        $this->onlyCurrent($qb);

        return array_values(array_map(
            static fn (array $row): array => [
                'organId' => (int) $row['organId'],
                'function' => InstallationFunctions::from((string) $row['function']),
            ],
            $qb->getQuery()->getScalarResult(),
        ));
    }

    /**
     * The members currently installed in the organ. An inactive member of a fraternity is not a
     * member of the active body group, so they are excluded unless asked for; a function filter
     * narrows the set to the officeholders of one function.
     *
     * @return list<int>
     */
    public function findCurrentMemberLidnrs(
        int $organId,
        ?InstallationFunctions $function = null,
        bool $includeInactive = false,
    ): array {
        $qb = $this->createQueryBuilder('om')
            ->select('IDENTITY(om.member)')
            ->distinct()
            ->join(
                'om.member',
                'm',
            )
            ->andWhere('om.organ = :organ')
            ->andWhere('m.deleted = false')
            ->setParameter(
                'organ',
                $organId,
            );
        $this->functionFilter(
            $qb,
            $function,
            $includeInactive,
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
        ?InstallationFunctions $function = null,
        bool $includeInactive = true,
    ): array {
        $qb = $this->createQueryBuilder('om')
            ->select('IDENTITY(om.member)')
            ->distinct()
            ->join(
                'om.member',
                'm',
            )
            ->andWhere('om.organ = :organ')
            ->andWhere('m.deleted = false')
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
        $this->functionFilter(
            $qb,
            $function,
            $includeInactive,
        );

        return array_values(array_map(
            static fn (array $row): int => (int) array_values($row)[0],
            $qb->getQuery()->getScalarResult(),
        ));
    }

    /**
     * The functions that ever had an installation in the organ. A function that is no longer held
     * still needs its group reconciled to an empty membership.
     *
     * @return list<InstallationFunctions>
     */
    public function findFunctionsByOrgan(int $organId): array
    {
        $rows = $this->createQueryBuilder('om')
            ->select('om.function AS function')
            ->distinct()
            ->andWhere('om.organ = :organ')
            ->setParameter(
                'organ',
                $organId,
            )
            ->getQuery()
            ->getScalarResult();

        return array_values(array_map(
            static fn (array $row): InstallationFunctions => InstallationFunctions::from((string) $row['function']),
            $rows,
        ));
    }

    /**
     * Every organ's functions in one query, for the reconciliation's group enumeration.
     *
     * @return array<int, list<InstallationFunctions>>
     */
    public function findAllFunctionRefsByOrgan(): array
    {
        $rows = $this->createQueryBuilder('om')
            ->select(
                'IDENTITY(om.organ) AS organId',
                'om.function AS function',
            )
            ->distinct()
            ->getQuery()
            ->getScalarResult();

        $functions = [];
        foreach ($rows as $row) {
            $functions[(int) $row['organId']][] = InstallationFunctions::from((string) $row['function']);
        }

        return $functions;
    }

    private function functionFilter(
        QueryBuilder $qb,
        ?InstallationFunctions $function,
        bool $includeInactive,
    ): void {
        if (null !== $function) {
            $qb->andWhere('om.function = :function')
                ->setParameter(
                    'function',
                    $function,
                );

            return;
        }

        if ($includeInactive) {
            return;
        }

        $qb->andWhere('om.function <> :inactive')
            ->setParameter(
                'inactive',
                InstallationFunctions::InactiveMember,
            );
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
