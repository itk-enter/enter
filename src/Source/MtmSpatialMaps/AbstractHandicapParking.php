<?php

declare(strict_types=1);

namespace App\Source\MtmSpatialMaps;

use App\Geo\Wgs84Transformer;
use App\Ngsi\NgsiEntity;
use App\Source\AbstractSource;

/**
 * The municipal register of disabled parking in Aarhus.
 */
abstract class AbstractHandicapParking extends AbstractSource
{
    // Models that this source produces.
    protected const string PARKING_SPOT = 'ParkingSpot';
    protected const string ON_STREET_PARKING = 'OnStreetParking';

    // Dataset reference.
    protected const string DATASET = 'mtm_spatialmaps-handicap-parking';
    protected const string DATASET_TITLE = 'Handicapparkering (MTM), Aarhus Kommune';

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

        // mi_prinx is assumed to be the feed's stable primary key.
        $key = $properties['mi_prinx'] ?? null;

        // Skip if no primary id.
        if (null === $key || '' === $key) {
            return null;
        }

        if (!$this->supports($properties)) {
            return null;
        }

        $model = $this->definition->model;

        $entity = new NgsiEntity(
            \sprintf('urn:ngsi-ld:%s:aarhus-handicap-%s', $model, $key),
            $model
        )
            ->setProperty('name', $this->address($properties))
            ->setProperty('description', trim((string) ($properties['bemrk'] ?? '')))
            ->setProperty('source', $this->definition->accessUrl)
            ->geoProperty('location', $transformer->transformGeometry($this->definition->crs, $geometry));

        return $this->describe($entity, $properties);
    }

    /**
     * Whether a record belongs to this source's model.
     *
     * @param array<string, mixed> $properties
     */
    abstract protected function supports(array $properties): bool;

    /**
     * Sets what only this source's model holds.
     *
     * @param array<string, mixed> $properties
     */
    abstract protected function describe(NgsiEntity $entity, array $properties): NgsiEntity;

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
