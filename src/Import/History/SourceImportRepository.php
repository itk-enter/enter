<?php

declare(strict_types=1);

namespace App\Import\History;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Reads the imports of the sources from the database.
 *
 * The methods give an ImportSummary for each import, with the number of
 * entities that the import added. The summaries are sorted by the start of
 * the import, oldest first.
 *
 * @extends ServiceEntityRepository<SourceImport>
 */
class SourceImportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SourceImport::class);
    }

    /**
     * Gets the imports that started at or after a time.
     *
     * @param string|null $sourceId the id of the one source to get, or null to get all the sources
     *
     * @return list<ImportSummary>
     *
     * @codeCoverageIgnore The query needs MariaDB, which the tests do not have.
     */
    public function findSummariesSince(\DateTimeImmutable $since, ?string $sourceId = null): array
    {
        $queryBuilder = $this->summaries($sourceId)
            ->andWhere('i.startedAt >= :since')
            ->setParameter('since', $since);

        /** @var list<ImportSummary> $summaries */
        $summaries = $queryBuilder->getQuery()->getResult();

        return $summaries;
    }

    /**
     * Gets the last import and the last successful import of each source. If
     * the last import succeeded, it is one import.
     *
     * @param string|null $sourceId the id of the one source to get, or null to get all the sources
     *
     * @return list<ImportSummary>
     *
     * @codeCoverageIgnore The query needs MariaDB, which the tests do not have.
     */
    public function findLatestSummaries(?string $sourceId = null): array
    {
        $last = $this->createQueryBuilder('j')
            ->select('MAX(j.id)')
            ->groupBy('j.sourceId');
        $lastSuccess = $this->createQueryBuilder('k')
            ->select('MAX(k.id)')
            ->where('k.status = :succeeded')
            ->groupBy('k.sourceId');

        $queryBuilder = $this->summaries($sourceId);
        $queryBuilder
            ->andWhere($queryBuilder->expr()->orX(
                $queryBuilder->expr()->in('i.id', $last->getDQL()),
                $queryBuilder->expr()->in('i.id', $lastSuccess->getDQL()),
            ))
            ->setParameter('succeeded', ImportStatus::Succeeded);

        /** @var list<ImportSummary> $summaries */
        $summaries = $queryBuilder->getQuery()->getResult();

        return $summaries;
    }

    /**
     * Gets the number of entities that the successful imports of each source
     * removed.
     *
     * @return array<string, int> the number of removed entities, by source id. A source without a successful import
     *                            is not in the list.
     *
     * @codeCoverageIgnore The query needs MariaDB, which the tests do not have.
     */
    public function findRemovedTotals(): array
    {
        /** @var list<array{sourceId: string, removed: int|string|null}> $rows */
        $rows = $this->createQueryBuilder('i')
            ->select('i.sourceId', 'SUM(i.deleted) AS removed')
            ->where('i.status = :succeeded')
            ->groupBy('i.sourceId')
            ->setParameter('succeeded', ImportStatus::Succeeded)
            ->getQuery()
            ->getArrayResult();

        $totals = [];
        foreach ($rows as $row) {
            $totals[$row['sourceId']] = (int) $row['removed'];
        }

        return $totals;
    }

    /**
     * Makes the query of the summaries, sorted by the start of the import.
     *
     * @param string|null $sourceId the id of the one source to get, or null to get all the sources
     *
     * @codeCoverageIgnore The query needs MariaDB, which the tests do not have.
     */
    private function summaries(?string $sourceId): QueryBuilder
    {
        $added = $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(c.id)')
            ->from(EntityChange::class, 'c')
            ->where('c.sourceImport = i')
            ->andWhere('c.type = :added');

        $queryBuilder = $this->createQueryBuilder('i')
            ->select(\sprintf(
                'NEW %s(
                    i.id, i.sourceId, i.startedAt, i.finishedAt, i.status, i.payloadHash, i.upserted, i.rejected,
                    i.deleted, (%s), i.failure
                )',
                ImportSummary::class,
                $added->getDQL(),
            ))
            ->orderBy('i.startedAt')
            ->addOrderBy('i.id')
            ->setParameter('added', EntityChangeType::Added);

        if (null !== $sourceId) {
            $queryBuilder
                ->andWhere('i.sourceId = :sourceId')
                ->setParameter('sourceId', $sourceId);
        }

        return $queryBuilder;
    }
}
