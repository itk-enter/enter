<?php

declare(strict_types=1);

namespace App\Conflation;

use App\Conflation\Data\MergedEntity;
use App\Conflation\Io\MergedDataSet;

/**
 * What one run of a merge read, produced and published.
 */
final readonly class ConflationRun
{
    /**
     * @param int                $records  the number of records that the run read
     * @param list<MergedEntity> $entities the merged entities that the run produced
     * @param int|null           $upserted the number of entities that the run published, or null if it published
     *                                     nothing
     * @param int|null           $deleted  the number of stale entities that the run deleted, or null if it published
     *                                     nothing
     */
    public function __construct(
        public MergedDataSet $dataSet,
        public int $records,
        public array $entities,
        public ?int $upserted = null,
        public ?int $deleted = null,
    ) {
    }

    public function published(int $upserted, int $deleted): self
    {
        return new self($this->dataSet, $this->records, $this->entities, $upserted, $deleted);
    }

    /**
     * The merged entities with at least one attribute that no approach
     * settled.
     *
     * @return list<MergedEntity>
     */
    public function withConflicts(): array
    {
        return array_values(array_filter($this->entities, static fn (MergedEntity $entity): bool => [] !== $entity->conflicts));
    }
}
