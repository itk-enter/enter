<?php

declare(strict_types=1);

namespace App\Source\MtmSpatialMaps;

use App\Ngsi\NgsiEntity;
use App\Source\DataType;
use App\Source\Definition;

/**
 * Locations with several disabled parking bays in the municipal register of
 * Aarhus.
 */
#[Definition(
    id: 'mtm_spatialmaps-handicap-parking-on-street',
    title: 'Handicapparkering på gaden (MTM), Aarhus Kommune',
    description: 'Locations in Aarhus Municipality with several disabled parking bays, with the number of reserved bays per location.',
    publisher: 'Aarhus Kommune',
    contact: 'ppg@aarhus.dk',
    landingPage: 'https://www.opendata.dk/city-of-aarhus/parkering-i-aarhus-kommune',
    accessUrl: 'https://webkort.aarhuskommune.dk/spatialmap?page=get_geojson_opendata&datasource=invap',
    dataType: DataType::GeoJSON,
    mediaType: 'application/geo+json',
    crs: 'EPSG:25832',
    model: self::ON_STREET_PARKING,
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
    dataset: self::DATASET,
    datasetTitle: self::DATASET_TITLE,
)]
final class OnStreetParking extends AbstractHandicapParking
{
    protected function describe(NgsiEntity $entity, array $properties): NgsiEntity
    {
        return $entity
            ->setProperty('category', ['forDisabled'])
            ->setProperty('totalSpotNumber', $this->bays($properties));
    }
}
