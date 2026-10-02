<?php

declare(strict_types=1);

namespace App\Conflation;

/**
 * The records judged to describe one real-world thing: at most one from
 * each data set.
 */
final readonly class Cluster
{
    /**
     * @param list<Record> $records ordered by data set, then id
     */
    public function __construct(
        public array $records,
    ) {
    }

    /**
     * The cluster's record from one data set, if it has one.
     */
    public function record(string $sourceId): ?Record
    {
        foreach ($this->records as $record) {
            if ($record->sourceId === $sourceId) {
                return $record;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function sourceIds(): array
    {
        return array_map(static fn (Record $record): string => $record->sourceId, $this->records);
    }
}
