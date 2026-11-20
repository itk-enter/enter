<?php

declare(strict_types=1);

namespace App\Import\History;

/**
 * The imports of one source on one day, in UTC.
 */
final readonly class HistoryDay
{
    /**
     * @param \DateTimeImmutable $date    the start of the day
     * @param int                $imports the number of imports that started on the day
     * @param int                $failed  the number of these imports that failed
     * @param int                $added   the number of added entities, without full loads
     * @param int                $removed the number of removed entities
     */
    public function __construct(
        public \DateTimeImmutable $date,
        public int $imports = 0,
        public int $failed = 0,
        public int $added = 0,
        public int $removed = 0,
    ) {
    }

    /**
     * Gets a copy of the day with one more import.
     */
    public function with(ImportSummary $import): self
    {
        return new self(
            $this->date,
            $this->imports + 1,
            $this->failed + ($import->isFailed() ? 1 : 0),
            $this->added + ($import->isFullLoad() ? 0 : $import->added),
            $this->removed + ($import->removed ?? 0),
        );
    }
}
