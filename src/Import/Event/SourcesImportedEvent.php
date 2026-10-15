<?php

declare(strict_types=1);

namespace App\Import\Event;

use App\Import\ImportResult;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched once a run over several sources has finished, whatever its
 * outcome, so work that depends on a complete set of imports, such as
 * conflation, can start from it.
 *
 * Every source the run attempted is either imported or failed, never both.
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
     * Whether a source was part of the run and imported successfully.
     */
    public function isImported(string $sourceId): bool
    {
        return isset($this->imported[$sourceId]);
    }

    /**
     * Whether every one of the given sources imported successfully in this
     * run. A source the run did not attempt counts as not imported.
     *
     * @param list<string> $sourceIds
     */
    public function areAllImported(array $sourceIds): bool
    {
        foreach ($sourceIds as $sourceId) {
            if (!$this->isImported($sourceId)) {
                return false;
            }
        }

        return true;
    }

    public function hasFailures(): bool
    {
        return [] !== $this->failed;
    }
}
