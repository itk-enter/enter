<?php

declare(strict_types=1);

namespace App\Test\Source\MtmSpatialMaps;

use App\Geo\Wgs84Transformer;
use App\Ngsi\NgsiEntity;
use App\Source\AbstractSource;

/**
 * The register of disabled parking bays in Aarhus Municipality.
 */
abstract class AbstractTestHandicapParking extends AbstractSource
{
    protected const string PARKING_SPOT = 'ParkingSpot';
    protected const string ON_STREET_PARKING = 'OnStreetParking';

    protected const string DATASET = 'test:mtm_spatialmaps-handicap-parking';
    protected const string DATASET_TITLE = 'Test: Handicapparkering (MTM), Aarhus Kommune';

    /**
     * Makes an NgsiEntity from one feed record.
     *
     * @param array<string, mixed> $data GeoJSON Feature
     */
    final public function createNgsiEntity(array $data, Wgs84Transformer $transformer): ?NgsiEntity
    {
        $properties = $data['properties'] ?? null;
        $geometry = $data['geometry'] ?? null;

        if (!\is_array($properties) || !\is_array($geometry)) {
            return null;
        }

        if (!$this->supports($properties)) {
            return null;
        }

        if ('' === $this->getKey($properties)) {
            return null;
        }

        return $this->buildNgsiEntity($properties, $geometry, $transformer);
    }

    /**
     * Returns true if the record is for the model of this source.
     *
     * @param array<string, mixed> $properties
     */
    abstract protected function supports(array $properties): bool;

    /**
     * Returns the model of the record.
     *
     * @param array<string, mixed> $properties
     */
    abstract protected function getModel(array $properties): string;

    /**
     * Returns the key of the record in the feed.
     *
     * @param array<string, mixed> $properties
     */
    abstract protected function getKey(array $properties): string;

    /**
     * Makes an entity with the properties that all models have.
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
     * Returns the number of reserved bays in the record.
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
