<?php

declare(strict_types=1);

namespace App\Conflation\Io;

use App\Broker\NgsiLdBroker;
use App\Conflation\Conflation;
use App\Conflation\Data\Record;
use App\Source\SourceInterface;
use App\SourceImporter\AbstractSourceImporter;
use App\SourceManager;
use Psr\Log\LoggerInterface;

/**
 * Reads the records a merge needs from the broker: the first step of a run.
 *
 * The pipeline works on Records, not on broker entities. This class decides
 * which data sets a merge reads, and reads their entities from the broker.
 * It turns each entity into a Record: its id, its data set, its location, and
 * the attributes that describe the thing.
 *
 * For example, for a merge with defining: ['PublicToilet'], it reads the four
 * toilet data sets. It turns the OSM toilet entity
 *
 *     {"id": "urn:…:aarhus-toilet-osm-node-17", "geometry": {"type": "Point", "coordinates": [10.21, 56.15]},
 *      "properties": {"type": "PublicToilet", "name": "Park toilet", "sourceId": "osm-public-toilet", …}}
 *
 * into a Record with that id, the data set "osm-public-toilet", the point
 * (10.21, 56.15) and the name "Park toilet".
 *
 * A merge names models, not data sets. The fetcher reads every data set whose
 * source publishes one of the defining or augmenting models of the merge.
 * Thus a new data set of a model takes part, and the merge does not change.
 * The fetcher reads the entities by the source id of the data set. The merge
 * publishes its result under its own source id, so the fetcher never reads
 * that result back in.
 *
 * The fetcher keeps the test data sets apart from the published data sets.
 * The source id of a test data set starts with "test:". A merge whose id
 * starts with "test:" reads only test data sets. Any other merge reads only
 * published data sets.
 */
final readonly class ConflationFetcher
{
    /**
     * The prefix of a test data set's source id, and of a test merge's id.
     */
    public const string TEST_PREFIX = 'test:';

    /**
     * Properties that the fetcher does not take as attributes of a record.
     *
     * - type: the model, which the record holds as its model.
     * - location: the broker returns the location twice, as the feature's
     *   geometry and again as this property. The record gets its location
     *   from the geometry, which the match compares. Thus the fetcher does not
     *   also take the copy as an attribute.
     * - sourceId and source: which data set the entity comes from, and where
     *   the importer read it. They are different in each data set, so they
     *   would put every group in conflict. The record already holds its data
     *   set.
     */
    public const array LEFT_OUT = [
        'type',
        'location',
        AbstractSourceImporter::SOURCE_ID_ATTRIBUTE,
        'source',
    ];

    /**
     * The property that holds the source fields that the model has no
     * attribute for. Its keys are the field names of each feed.
     */
    public const string ADDITIONAL_INFORMATION = 'additionalInformation';

    /**
     * Joins additionalInformation and one of its keys into the name of the
     * attribute that the pipeline settles the key as.
     */
    public const string KEY_SEPARATOR = '.';

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
     * An entity without a geometry has no location, so the fetcher leaves it
     * out and logs a warning.
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
     * The data sets that a merge reads, ordered by source id. These are the
     * data sets that publish one of its models: test data sets for a test
     * merge, and published data sets for any other merge.
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
        $attributes = self::splitAdditionalInformation(array_diff_key($properties, array_flip(self::LEFT_OUT)));
        // A value the broker returns as null is one the record does not state.
        $attributes = array_filter($attributes, static fn (mixed $value): bool => null !== $value);

        return Record::fromGeometry($id, $sourceId, $geometry, $model, $attributes);
    }

    /**
     * Splits additionalInformation into one attribute per key. Thus the
     * pipeline settles each key on its own, with the same rules as any other
     * attribute.
     *
     * If the data sets agree on a key, or only one data set states it, the key
     * has no conflict. If two data sets state different values for a key, the
     * approaches of the merge settle it, or the pipeline records a conflict.
     * Each key becomes an attribute with the name "additionalInformation.<key>".
     * The method keeps a list as one value.
     *
     * @param array<array-key, mixed> $attributes
     *
     * @return array<array-key, mixed>
     */
    private static function splitAdditionalInformation(array $attributes): array
    {
        $information = $attributes[self::ADDITIONAL_INFORMATION] ?? null;
        if (!\is_array($information) || array_is_list($information)) {
            return $attributes;
        }

        unset($attributes[self::ADDITIONAL_INFORMATION]);
        foreach ($information as $key => $value) {
            $attributes[self::ADDITIONAL_INFORMATION.self::KEY_SEPARATOR.$key] = $value;
        }

        return $attributes;
    }

    private static function isTest(string $id): bool
    {
        return str_starts_with($id, self::TEST_PREFIX);
    }
}
