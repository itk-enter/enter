<?php

declare(strict_types=1);

namespace App\Conflation;

use App\Broker\NgsiLdBroker;
use App\Source\SourceInterface;
use App\SourceImporter\AbstracSourceImporter;
use App\SourceManager;
use Psr\Log\LoggerInterface;

/**
 * Reads the records a merge needs from the broker: the fetch step.
 *
 * A merge names models, not data sets. Every data set whose source publishes
 * one of the merge's defining or augmenting models is read, so a data set
 * added for a model takes part without changing the merge. The entities are
 * read by the data set's source id, so the merge's own result, published under
 * its own source id, is never read back in.
 *
 * Test data sets, whose source ids start with "test:", are kept apart from
 * published ones: a merge whose id starts with "test:" reads only test data
 * sets, and any other merge reads only published ones.
 */
final readonly class ConflationFetcher
{
    /**
     * The prefix of a test data set's source id, and of a test merge's id.
     */
    public const string TEST_PREFIX = 'test:';

    /**
     * Attributes left out of every record.
     *
     * They describe the entity or the data set it comes from rather than the
     * thing, or cannot be compared between data sets, so keeping them would
     * put every group in conflict over them:
     *
     * - type: the model, which the record carries already.
     * - location: the geometry, which the record carries already.
     * - sourceId and source: which data set, and where it was read from.
     * - additionalInformation: the source fields the model has no attribute
     *   for, keyed by each feed's own field names.
     */
    public const array LEFT_OUT = [
        'type',
        'location',
        AbstracSourceImporter::SOURCE_ID_ATTRIBUTE,
        'source',
        'additionalInformation',
    ];

    public function __construct(
        private SourceManager $sources,
        private NgsiLdBroker $broker,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Every record of the models the merge names, from every data set that
     * publishes them, ordered by data set, then id.
     *
     * An entity without a geometry cannot be placed, so it is left out with a
     * warning.
     *
     * @return list<Record>
     */
    public function fetch(Conflation $conflation): array
    {
        $records = [];
        foreach ($this->dataSets($conflation) as $source) {
            $definition = $source->definition;
            foreach ($this->broker->features($definition->id, $definition->contextUrl) as $feature) {
                $record = $this->record($feature, $definition->id, $definition->model);
                if (null === $record) {
                    $this->logger->warning('Left out {id} of {source}: it has no geometry.', ['id' => $feature['id'] ?? '?', 'source' => $definition->id]);
                    continue;
                }
                $records[] = $record;
            }
        }

        usort($records, static fn (Record $x, Record $y): int => [$x->sourceId, $x->id] <=> [$y->sourceId, $y->id]);

        return $records;
    }

    /**
     * The data sets a merge reads: those publishing one of its models, test
     * data sets for a test merge and published ones otherwise, ordered by
     * source id.
     *
     * @return list<SourceInterface>
     */
    public function dataSets(Conflation $conflation): array
    {
        $models = [...$conflation->defining, ...array_keys($conflation->augmenting)];
        $test = self::isTest($conflation->id);

        $dataSets = array_values(array_filter(
            $this->sources->getSources(),
            static fn (SourceInterface $source): bool => \in_array($source->definition->model, $models, true)
                && self::isTest($source->definition->id) === $test,
        ));
        usort($dataSets, static fn (SourceInterface $x, SourceInterface $y): int => strcmp($x->definition->id, $y->definition->id));

        return $dataSets;
    }

    /**
     * A record from one feature the broker returns.
     *
     * @param array<string, mixed> $feature
     */
    private function record(array $feature, string $sourceId, string $model): ?Record
    {
        $id = $feature['id'] ?? null;
        $geometry = $feature['geometry'] ?? null;
        if (!\is_string($id) || !\is_array($geometry) || !isset($geometry['type'])) {
            return null;
        }

        $properties = \is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
        $attributes = array_diff_key($properties, array_flip(self::LEFT_OUT));
        // A value the broker returns as null is one the record does not state.
        $attributes = array_filter($attributes, static fn (mixed $value): bool => null !== $value);

        return Record::fromGeometry($id, $sourceId, $geometry, $model, $attributes);
    }

    private static function isTest(string $id): bool
    {
        return str_starts_with($id, self::TEST_PREFIX);
    }
}
