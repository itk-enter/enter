<?php

declare(strict_types=1);

namespace App\Conflation;

use App\Conflation\Io\ConflationFetcher;
use App\Import\History\ImportHistory;
use Psr\Clock\ClockInterface;

/**
 * Tells if a merge has new data to read.
 *
 * A merge has new data if at least one of its data sets got new data in the
 * last 24 hours. The import history tells if a data set got new data. The
 * other data sets of the merge take part with the entities that the broker
 * already has, also when their last import failed.
 */
final readonly class NewDataCheck
{
    public function __construct(
        private ConflationFetcher $fetcher,
        private ImportHistory $history,
        private ClockInterface $clock,
    ) {
    }

    public function hasNewData(Conflation $conflation): bool
    {
        $since = $this->clock->now()->modify('-24 hours');

        return array_any($this->fetcher->dataSets($conflation), fn ($dataSet) => $this->history->hasNewDataSince($dataSet->definition->id, $since));
    }
}
