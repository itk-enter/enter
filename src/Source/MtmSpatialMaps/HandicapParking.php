<?php

declare(strict_types=1);

namespace App\Source\MtmSpatialMaps;

use App\Geo\Wgs84Transformer;
use App\Ngsi\NgsiEntity;
use App\Source\AbstractSource;
use App\Source\DataType;
use App\Source\Definition;

/**
 * Disabled parking bays in Aarhus Municipality.
 */
#[Definition(
    id: 'mtm_spatialmaps-handicap-parking',
    title: 'Handicapparkering, Aarhus Kommune',
    description: 'Disabled parking bays in Aarhus Municipality, with the number of reserved bays per location.',
    publisher: 'Aarhus Kommune',
    contact: 'ppg@aarhus.dk',
    landingPage: 'https://www.opendata.dk/city-of-aarhus/parkering-i-aarhus-kommune',
    accessUrl: 'https://webkort.aarhuskommune.dk/spatialmap?page=get_geojson_opendata&datasource=invap',
    dataType: DataType::GeoJSON,
    mediaType: 'application/geo+json',
    crs: 'EPSG:25832',
    models: ['ParkingSpot', 'OnStreetParking'],
    contextUrl: 'https://raw.githubusercontent.com/smart-data-models/dataModel.Parking/master/context.jsonld',
    updateFrequency: 'continuous',

    // The portal states no licence for this data set. DCAT-AP requires one, so
    // it has to be settled with the data owner before the catalogue can be
    // registered anywhere.
    licence: null,

    omittedFields: [
        'ident' => 'Code of varying shape (single letters, numbers, pairs of numbers); its meaning is not documented and not confirmed by the data owner.',
        'oprettet_af' => 'Directory username of the municipal employee who created the record.',
        'rettet_af' => 'Directory username of the municipal employee who last edited the record.',
        'oprettet_dato' => 'Describes the register record.',
        'rettet_dato' => 'Describes the register record.',
        'mi_style' => 'MapInfo rendering style, empty throughout the export.',
    ],
)]
final class HandicapParking extends AbstractSource
{
    private const string PARKING_SPOT = 'ParkingSpot';
    private const string ON_STREET_PARKING = 'OnStreetParking';

    /**
     * Maps one feed record onto an NgsiEntity.
     *
     * @param array<string, mixed> $data GeoJSON Feature
     */
    public function createNgsiEntity(array $data, Wgs84Transformer $transformer): ?NgsiEntity
    {
        $row = $data['properties'] ?? null;
        $geometry = $data['geometry'] ?? null;

        if (!\is_array($row) || !\is_array($geometry)) {
            return null;
        }

        // mi_prinx is the feed's stable primary key. Without it there is no
        // way to address the same bay again on the next import, and an upsert
        // would create duplicates instead of updating.
        $key = $row['mi_prinx'] ?? null;
        if (null === $key || '' === $key) {
            return null;
        }

        // If a number of bays are not defined, assume that the entry cover a single parking spot.
        $bays = $this->bays($row);
        $model = 1 === $bays ? self::PARKING_SPOT : self::ON_STREET_PARKING;

        $entity = new NgsiEntity(
            \sprintf('urn:ngsi-ld:%s:aarhus-handicap-%s', $model, $key),
            $model
        )
            ->setProperty('name', $this->address($row))
            ->setProperty('description', trim((string) ($row['bemrk'] ?? '')))
            ->setProperty('source', $this->definition->accessUrl)
            ->geoProperty('location', $transformer->transformGeometry($this->definition->crs, $geometry));

        if (self::PARKING_SPOT === $model) {
            return $entity
                // The model "requires" an occupancy status.
                ->setProperty('status', 'unknown')
                // This assumes that the municipal register only defines "onstreet" parking spots.
                ->setProperty('category', ['onStreet']);
        }

        return $entity
            ->setProperty('category', ['forDisabled'])
            ->setProperty('totalSpotNumber', $bays);
    }

    /**
     * The number of reserved bays the record counts. The register's grain is
     * the bay, and a record with the count left blank is a bay entered
     * without one, so it counts as one.
     *
     * @param array<string, mixed> $row
     */
    private function bays(array $row): int
    {
        $value = $row['invalidepladser'] ?? null;

        return is_numeric($value) ? (int) $value : 1;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function address(array $row): string
    {
        return trim(\sprintf(
            '%s %s',
            trim((string) ($row['vejnavn'] ?? '')),
            trim((string) ($row['husnnr'] ?? ''))
        ));
    }
}
