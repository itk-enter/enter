<?php

declare(strict_types=1);

namespace App\Test\Source\MtmSpatialMaps;

use App\Geo\Wgs84Transformer;
use App\Ngsi\NgsiEntity;
use App\Source\AbstractSource;

/**
 * The municipal register of disabled parking in Aarhus.
 */
abstract class AbstractTestHandicapParking extends AbstractSource
{
    // Models that this source produces.
    protected const string PARKING_SPOT = 'ParkingSpot';
    protected const string ON_STREET_PARKING = 'OnStreetParking';

    // Dataset reference.
    protected const string DATASET = 'test:mtm_spatialmaps-handicap-parking';
    protected const string DATASET_TITLE = 'Test: Handicapparkering (MTM), Aarhus Kommune';

    /**
     * Maps one feed record onto an NgsiEntity, or null when the record
     * belongs to the other model.
     *
     * @param array<string, mixed> $data GeoJSON Feature
     */
    final public function createNgsiEntity(array $data, Wgs84Transformer $transformer): ?NgsiEntity
    {
        $properties = $data['properties'] ?? null;
        $geometry = $data['geometry'] ?? null;

        // Skip if no properties or geometry is defined.
        if (!\is_array($properties) || !\is_array($geometry)) {
            return null;
        }

        if (!$this->supports($properties)) {
            return null;
        }

        // Skip if no primary id.
        if ('' === $this->getKey($properties)) {
            return null;
        }

        return $this->buildNgsiEntity($properties, $geometry, $transformer);
    }

    /**
     * Whether a record belongs to this source's model.
     *
     * @param array<string, mixed> $properties
     */
    abstract protected function supports(array $properties): bool;

    /**
     * The model the record is published under. Only called if supports()
     * accepted the record.
     *
     * @param array<string, mixed> $properties
     */
    abstract protected function getModel(array $properties): string;

    /**
     * The record's stable key in the feed, or '' when it has none. Only
     * called if supports() accepted the record.
     *
     * @param array<string, mixed> $properties
     */
    abstract protected function getKey(array $properties): string;

    /**
     * Maps a record onto an entity with what every model holds. Only called
     * if supports() accepted the record; sources extend it with what only
     * their model holds.
     *
     * @param array<string, mixed> $properties
     * @param array<string, mixed> $geometry
     */
    protected function buildNgsiEntity(array $properties, array $geometry, Wgs84Transformer $transformer): NgsiEntity
    {
        $model = $this->getModel($properties);

        return new NgsiEntity(
            \sprintf('urn:ngsi-ld:%s:aarhus-handicap-%s', $model, $this->getKey($properties)),
            $model
        )
            ->setProperty('name', $this->address($properties))
            ->setProperty('description', trim((string) ($properties['bemrk'] ?? '')))
            ->setProperty('source', $this->definition->accessUrl)
            ->geoProperty('location', $transformer->transformGeometry($this->definition->crs, $geometry));
    }

    /**
     * The number of reserved bays the record counts. The register's grain is
     * the bay, and a record with the count left blank is a bay entered
     * without one, so it counts as one.
     *
     * @param array<string, mixed> $properties
     */
    final protected function bays(array $properties): int
    {
        $value = $properties['invalidepladser'] ?? null;

        return is_numeric($value) ? (int) $value : 1;
    }

    /**
     * @param array<string, mixed> $properties
     */
    private function address(array $properties): string
    {
        return trim(\sprintf(
            '%s %s',
            trim((string) ($properties['vejnavn'] ?? '')),
            trim((string) ($properties['husnnr'] ?? ''))
        ));
    }
}
