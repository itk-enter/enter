<?php

declare(strict_types=1);

namespace App\Source\MtmSpatialMaps;

use App\Geo\Wgs84Transformer;
use App\Ngsi\NgsiEntity;
use App\Source\DataType;
use App\Source\Definition;

/**
 * Single disabled parking bays in the municipal register of Aarhus.
 */
#[Definition(
    id: 'mtm_spatialmaps-handicap-parking-spot',
    title: 'Handicapparkeringspladser (MTM), Aarhus Kommune',
    description: 'Single disabled parking bays in Aarhus Municipality, one per register record of one bay.',
    publisher: 'Aarhus Kommune',
    contact: 'ppg@aarhus.dk',
    landingPage: 'https://www.opendata.dk/city-of-aarhus/parkering-i-aarhus-kommune',
    accessUrl: 'https://webkort.aarhuskommune.dk/spatialmap?page=get_geojson_opendata&datasource=invap',
    dataType: DataType::GeoJSON,
    mediaType: 'application/geo+json',
    crs: 'EPSG:25832',
    model: self::PARKING_SPOT,
    contextUrl: 'https://raw.githubusercontent.com/smart-data-models/dataModel.Parking/master/context.jsonld',
    updateFrequency: 'continuous',

    // The portal states no licence for this data set. DCAT-AP requires one, so
    // it has to be settled with the data owner before the catalogue can be
    // registered anywhere.
    licence: null,

    omittedFields: [
        'ident' => 'Code of varying shape (single letters, numbers, pairs of numbers); its meaning is not documented and not confirmed by the data owner.',
        'invalidepladser' => 'Decides whether a record is a single bay; for one it is the one bay, and the model counts none.',
        'oprettet_af' => 'Directory username of the municipal employee who created the record.',
        'rettet_af' => 'Directory username of the municipal employee who last edited the record.',
        'oprettet_dato' => 'Describes the register record.',
        'rettet_dato' => 'Describes the register record.',
        'mi_style' => 'MapInfo rendering style, empty throughout the export.',
    ],
    dataset: self::DATASET,
    datasetTitle: self::DATASET_TITLE,
)]
final class ParkingSpot extends AbstractHandicapParking
{
    protected function supports(array $properties): bool
    {
        return 1 === $this->bays($properties);
    }

    protected function getModel(array $properties): string
    {
        return self::PARKING_SPOT;
    }

    protected function getKey(array $properties): string
    {
        // mi_prinx is assumed to be the feed's stable primary key.
        return (string) ($properties['mi_prinx'] ?? '');
    }

    protected function buildNgsiEntity(array $properties, array $geometry, Wgs84Transformer $transformer): NgsiEntity
    {
        return parent::buildNgsiEntity($properties, $geometry, $transformer)
            // The model requires an occupancy status.
            ->setProperty('status', 'unknown')
            // The register holds street parking only.
            ->setProperty('category', ['onStreet']);
    }
}
