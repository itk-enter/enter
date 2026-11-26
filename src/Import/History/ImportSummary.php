<?php

declare(strict_types=1);

namespace App\Import\History;

/**
 * The figures of one import of one source, as the import history page shows
 * them. ImportHistory makes it from a SourceImport and its EntityChange rows.
 */
final readonly class ImportSummary
{
    /**
     * @param int|null $upserted the number of upserted entities, or null if the import did not succeed
     * @param int|null $rejected the number of entities that the broker rejected, or null if the import did not succeed
     * @param int|null $removed  the number of deleted stale entities, or null if the import did not succeed
     * @param int      $added    the number of entities that the broker did not have before the import
     */
    public function __construct(
        public int $id,
        public string $sourceId,
        public \DateTimeImmutable $startedAt,
        public ?\DateTimeImmutable $finishedAt,
        public ImportStatus $status,
        public ?string $payloadHash,
        public ?int $upserted,
        public ?int $rejected,
        public ?int $removed,
        public int $added,
        public ?string $failure,
    ) {
    }

    public function isSucceeded(): bool
    {
        return ImportStatus::Succeeded === $this->status;
    }

    public function isFailed(): bool
    {
        return ImportStatus::Failed === $this->status;
    }

    /**
     * The time from the start to the end of the import, in seconds. Null if
     * the import did not finish.
     */
    public function duration(): ?int
    {
        return null === $this->finishedAt ? null : $this->finishedAt->getTimestamp() - $this->startedAt->getTimestamp();
    }

    /**
     * Tells if the import added all the entities that it upserted. This
     * occurs at the first import of a source, or after the broker lost the
     * entities of the source.
     */
    public function isFullLoad(): bool
    {
        return $this->isSucceeded() && $this->upserted > 0 && $this->added === $this->upserted;
    }

    /**
     * Tells if the import added or removed one or more entities.
     */
    public function changedEntities(): bool
    {
        return $this->added > 0 || $this->removed > 0;
    }
}
