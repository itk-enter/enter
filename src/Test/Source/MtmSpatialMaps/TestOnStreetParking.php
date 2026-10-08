<?php

declare(strict_types=1);

namespace App\Test\Source\MtmSpatialMaps;

use App\Ngsi\NgsiEntity;
use App\Source\DataType;
use App\Test\Source\TestDefinition;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Locations with several disabled parking bays in the municipal register of
 * Aarhus.
 */
#[When('dev')]
#[When('test')]
#[TestDefinition(
    // By convention the ID as a test source must start with `test:`
    id: 'test:mtm_spatialmaps-handicap-parking-on-street',
    title: 'Test: Handicapparkering på gaden (MTM), Aarhus Kommune',
    accessUrl: 'http://nginx:8080/test/data/webkort.aarhuskommune.dk/spatialmap?mtm_spatialmaps-handicap-parking',
    dataType: DataType::GeoJSON,
    mediaType: 'application/geo+json',
    crs: 'EPSG:25832',
    model: self::ON_STREET_PARKING,
    contextUrl: 'https://raw.githubusercontent.com/smart-data-models/dataModel.Parking/master/context.jsonld',
    omittedFields: [
        'ident' => 'Code of varying shape (single letters, numbers, pairs of numbers); its meaning is not documented and not confirmed by the data owner.',
        'oprettet_af' => 'Directory username of the municipal employee who created the record.',
        'rettet_af' => 'Directory username of the municipal employee who last edited the record.',
        'oprettet_dato' => 'Describes the register record.',
        'rettet_dato' => 'Describes the register record.',
        'mi_style' => 'MapInfo rendering style, empty throughout the export.',
    ],
    dataUrlBase: 'https://webkort.aarhuskommune.dk/spatialmap?page=get_geojson_opendata&datasource=invap',
    dataset: self::DATASET,
    datasetTitle: self::DATASET_TITLE,
)]
final class TestOnStreetParking extends AbstractTestHandicapParking
{
    protected function describe(NgsiEntity $entity, array $row): NgsiEntity
    {
        return $entity
            ->setProperty('category', ['forDisabled'])
            ->setProperty('totalSpotNumber', $this->bays($row));
    }
}
