<?php

declare(strict_types=1);

namespace App\Import\Event;

use App\Import\ImportResult;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * The importer dispatches this event when a run of several sources is
 * complete, also if a source failed. Work that needs a complete set of
 * imports, such as conflation, can start from this event.
 *
 * Each source that the run tried to import is in imported or in failed. A
 * source is never in both.
 */
final class SourcesImportedEvent extends Event
{
    /**
     * @param array<string, ImportResult> $imported results by source id
     * @param array<string, \Throwable>   $failed   errors by source id
     */
    public function __construct(
        public readonly array $imported,
        public readonly array $failed,
    ) {
    }

    /**
     * Tells if the source was part of the run and if its import was successful.
     */
    public function isImported(string $sourceId): bool
    {
        return isset($this->imported[$sourceId]);
    }

    /**
     * Tells if the import of each given source was successful in this run.
     * If the run did not try to import a source, the source counts as not
     * imported.
     *
     * @param list<string> $sourceIds
     */
    public function areAllImported(array $sourceIds): bool
    {
        return array_all($sourceIds, fn ($sourceId) => $this->isImported($sourceId));
    }

    public function hasFailures(): bool
    {
        return [] !== $this->failed;
    }
}
