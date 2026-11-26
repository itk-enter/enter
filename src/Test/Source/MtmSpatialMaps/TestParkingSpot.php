<?php

declare(strict_types=1);

namespace App\Test\Source\MtmSpatialMaps;

use App\Source\DataType;
use App\Source\MtmSpatialMaps\AbstractParkingSpot;
use App\Test\Source\TestDefinition;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Single disabled parking bays in Aarhus Municipality.
 */
#[When('dev')]
#[When('test')]
#[TestDefinition(
    id: 'test:mtm_spatialmaps-handicap-parking-spot',
    title: 'Test: Handicapparkeringspladser (MTM), Aarhus Kommune',
    accessUrl: 'http://nginx:8080/test/data/webkort.aarhuskommune.dk/spatialmap?mtm_spatialmaps-handicap-parking',
    dataType: DataType::GeoJSON,
    mediaType: 'application/geo+json',
    crs: 'EPSG:25832',
    model: self::PARKING_SPOT,
    contextUrl: 'https://raw.githubusercontent.com/smart-data-models/dataModel.Parking/master/context.jsonld',
    omittedFields: [
        'ident' => 'Code of varying shape (single letters, numbers, pairs of numbers); its meaning is not documented and not confirmed by the data owner.',
        'invalidepladser' => 'Decides whether a record is a single bay; for one it is the one bay, and the model counts none.',
        'oprettet_af' => 'Directory username of the municipal employee who created the record.',
        'rettet_af' => 'Directory username of the municipal employee who last edited the record.',
        'oprettet_dato' => 'Describes the register record.',
        'rettet_dato' => 'Describes the register record.',
        'mi_style' => 'MapInfo rendering style, empty throughout the export.',
    ],
    dataUrlBase: 'https://webkort.aarhuskommune.dk/spatialmap?page=get_geojson_opendata&datasource=invap',
    dataset: 'test:mtm_spatialmaps-handicap-parking',
    datasetTitle: 'Test: Handicapparkering (MTM), Aarhus Kommune',
)]
final class TestParkingSpot extends AbstractParkingSpot
{
}
