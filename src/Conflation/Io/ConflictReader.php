<?php

declare(strict_types=1);

namespace App\Conflation\Io;

use App\Broker\NgsiLdBroker;
use App\SourceManager;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

/**
 * Reads the conflicts of a merged data set from the broker.
 *
 * The broker holds the merged entities that the last run of the merge
 * published. Thus the overview shows the conflicts of that run.
 */
final readonly class ConflictReader
{
    public function __construct(
        private NgsiLdBroker $broker,
        private SourceManager $sources,
    ) {
    }

    /**
     * Reads the merged entities that have conflicts, or gives null if the
     * broker is not available.
     */
    public function read(MergedDataSet $dataSet): ?ConflictOverview
    {
        $entities = [];
        try {
            foreach ($this->broker->conflictedFeatures($dataSet->id, $dataSet->contextUrl) as $feature) {
                $entity = ConflictedEntity::fromFeature($feature);
                if (null !== $entity) {
                    $entities[] = $entity;
                }
            }
        } catch (\RuntimeException|ExceptionInterface) {
            return null;
        }

        usort($entities, static fn (ConflictedEntity $a, ConflictedEntity $b): int => [\count($b->conflicts), $a->title()] <=> [\count($a->conflicts), $b->title()]);

        $titles = [];
        foreach ($dataSet->inputs as $id) {
            $titles[$id] = ($this->sources->getSources()[$id] ?? null)?->definition->title ?? $id;
        }

        return new ConflictOverview($dataSet, $entities, $titles);
    }
}
