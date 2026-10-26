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
     * @param int                $records  the records read
     * @param list<MergedEntity> $entities the merged entities produced
     * @param int|null           $upserted the entities published, null when nothing was published
     * @param int|null           $deleted  the stale entities deleted, null when nothing was published
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
     * The merged entities with at least one attribute no approach settled.
     *
     * @return list<MergedEntity>
     */
    public function withConflicts(): array
    {
        return array_values(array_filter($this->entities, static fn (MergedEntity $entity): bool => [] !== $entity->conflicts));
    }
}
