<?php

declare(strict_types=1);

namespace App\Import\History;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Reads the entities that the imports added to the broker or removed from it.
 *
 * @extends ServiceEntityRepository<EntityChange>
 */
class EntityChangeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EntityChange::class);
    }

    /**
     * Gets the number of entities that each successful import added.
     *
     * @return list<array{sourceId: string, upserted: int|null, added: int}> one row for each successful import that
     *                                                                       added one or more entities
     *
     * @codeCoverageIgnore The query needs MariaDB, which the tests do not have.
     */
    public function findAddedCounts(): array
    {
        /** @var list<array{sourceId: string, upserted: int|null, added: int|string}> $rows */
        $rows = $this->createQueryBuilder('c')
            ->select('i.sourceId', 'i.upserted', 'COUNT(c.id) AS added')
            ->join('c.sourceImport', 'i')
            ->where('c.type = :added')
            ->andWhere('i.status = :succeeded')
            ->groupBy('i.id')
            ->addGroupBy('i.sourceId')
            ->addGroupBy('i.upserted')
            ->setParameter('added', EntityChangeType::Added)
            ->setParameter('succeeded', ImportStatus::Succeeded)
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn (array $row): array => ['sourceId' => $row['sourceId'], 'upserted' => $row['upserted'], 'added' => (int) $row['added']],
            $rows,
        );
    }

    /**
     * Gets the last entities that the imports of a source added or removed,
     * with their imports. The last change is first.
     *
     * @param int $limit the maximum number of changes
     *
     * @return list<EntityChange>
     *
     * @codeCoverageIgnore The query needs MariaDB, which the tests do not have.
     */
    public function findLatestForSource(string $sourceId, int $limit): array
    {
        /** @var list<EntityChange> $changes */
        $changes = $this->createQueryBuilder('c')
            ->addSelect('i')
            ->join('c.sourceImport', 'i')
            ->where('i.sourceId = :sourceId')
            ->orderBy('c.id', 'DESC')
            ->setParameter('sourceId', $sourceId)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $changes;
    }
}
