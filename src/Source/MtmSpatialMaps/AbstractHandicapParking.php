<?php

declare(strict_types=1);

namespace App\Source\MtmSpatialMaps;

use App\Geo\Wgs84Transformer;
use App\Ngsi\NgsiEntity;
use App\Source\AbstractSource;

/**
 * The municipal register of disabled parking in Aarhus, one source per model.
 *
 * The register's grain varies: most records are one bay each, several of
 * which may share an address, while some are a location with a count of
 * bays. A record of one bay is published as that bay, the rest as a site
 * with the bays it counts, so a bay here and the same bay in another source
 * come out under one model. Each source declares the same feed and keeps
 * only the records of its own model, so the rule that sorts records into
 * models lives here: were it to drift between the sources, a record would
 * be published twice or not at all, and nothing would say so.
 */
abstract class AbstractHandicapParking extends AbstractSource
{
    protected const string PARKING_SPOT = 'ParkingSpot';
    protected const string ON_STREET_PARKING = 'OnStreetParking';

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

        if (!\is_array($properties) || !\is_array($geometry)) {
            return null;
        }

        // mi_prinx is the feed's stable primary key. Without it there is no
        // way to address the same bay again on the next import, and an upsert
        // would create duplicates instead of updating.
        $key = $properties['mi_prinx'] ?? null;
        if (null === $key || '' === $key) {
            return null;
        }

        $model = $this->definition->model;
        if ($this->model($properties) !== $model) {
            return null;
        }

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
    private function model(array $properties): string
    {
        return 1 === $this->bays($properties) ? self::PARKING_SPOT : self::ON_STREET_PARKING;
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
