<?php

declare(strict_types=1);

namespace App\Conflation\Io;

use App\Broker\NgsiLdBroker;
use App\Conflation\Conflation;
use App\Conflation\Data\Record;
use App\Source\SourceInterface;
use App\SourceImporter\AbstracSourceImporter;
use App\SourceManager;
use Psr\Log\LoggerInterface;

/**
 * Reads the records a merge needs from the broker: the first step of a run.
 *
 * The pipeline works on Records, not on broker entities. This class decides
 * which data sets a merge reads, reads their entities from the broker, and
 * turns each entity into a Record: its id, its data set, its location, and
 * the attributes that describe the thing.
 *
 * For example, for a merge with defining: ['PublicToilet'], it reads the four
 * toilet data sets, and the OSM toilet entity
 *
 *     {"id": "urn:…:aarhus-toilet-osm-node-17", "geometry": {"type": "Point", "coordinates": [10.21, 56.15]},
 *      "properties": {"type": "PublicToilet", "name": "Park toilet", "sourceId": "osm-public-toilet", …}}
 *
 * becomes a Record with that id, data set "osm-public-toilet", located at
 * (10.21, 56.15), stating name "Park toilet".
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
     * Properties not taken as attributes of a record.
     *
     * - type: the model, which the record carries as its model.
     * - location: the broker returns the location twice, as the feature's
     *   geometry and again as this property. The record is located by the
     *   geometry, which matching compares, so the copy is not also taken as an
     *   attribute.
     * - sourceId and source: which data set the entity comes from and where it
     *   was read. They differ between data sets by definition, so they would
     *   put every group in conflict; the record carries its data set already.
     */
    public const array LEFT_OUT = [
        'type',
        'location',
        AbstracSourceImporter::SOURCE_ID_ATTRIBUTE,
        'source',
    ];

    /**
     * The property holding the source fields the model has no attribute for,
     * keyed by each feed's own field names.
     */
    public const string ADDITIONAL_INFORMATION = 'additionalInformation';

    /**
     * Joins additionalInformation and one of its keys into the name of the
     * attribute the key is settled as.
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
        $attributes = self::splitAdditionalInformation(array_diff_key($properties, array_flip(self::LEFT_OUT)));
        // A value the broker returns as null is one the record does not state.
        $attributes = array_filter($attributes, static fn (mixed $value): bool => null !== $value);

        return Record::fromGeometry($id, $sourceId, $geometry, $model, $attributes);
    }

    /**
     * Splits additionalInformation into one attribute per key, so each key is
     * settled on its own, by the same rules as any other attribute.
     *
     * Data sets that agree on a key, or where only one states it, keep it
     * without conflict, and a key two data sets state differently is settled
     * by the merge's approaches or recorded as a conflict. For example,
     * {"additionalInformation": {"check_date": "2025-06-01", "operator": "Aarhus Kommune"}}
     * becomes the attributes "additionalInformation.check_date" and
     * "additionalInformation.operator". A value that is not keyed, such as a
     * list, is kept whole.
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
