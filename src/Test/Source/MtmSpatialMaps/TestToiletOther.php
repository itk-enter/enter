<?php

declare(strict_types=1);

namespace App\Test\Source\MtmSpatialMaps;

use App\Source\DataType;
use App\Source\MtmSpatialMaps\AbstractToiletOther;
use App\Test\Source\TestDefinition;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Public toilets outside the city-kiosk network in Aarhus Municipality.
 */
#[When('dev')]
#[When('test')]
#[TestDefinition(
    id: 'test:mtm_spatialmaps-toilet-other',
    title: 'Test: Andre toiletter (MTM), Aarhus Kommune',
    accessUrl: 'http://nginx:8080/test/data/webkort.aarhuskommune.dk/spatialmap?mtm_spatialmaps-toilet-other',
    dataType: DataType::GeoJSON,
    mediaType: 'application/geo+json',
    crs: 'EPSG:25832',
    model: 'PublicToilet',
    contextUrl: 'https://raw.githubusercontent.com/itk-enter/data-models/PublicToilet/v0.0.2/dataModel.PointOfInterest/context.jsonld',
    omittedFields: [
        'bookbar' => 'Bookable flag; constant "Nej" throughout the export.',
        'oprettet_af' => 'Directory username of the municipal employee who created the record; personal data, and not a fact about the toilet.',
        'rettet_af' => 'Directory username of the municipal employee who last edited the record; personal data, and not a fact about the toilet.',
        'mi_style' => 'MapInfo rendering style.',
    ],
    dataUrlBase: 'https://webkort.aarhuskommune.dk/spatialmap?page=get_geojson_opendata&datasource=andre_toiletter',
    dataset: 'test:mtm_spatialmaps-toilet-other',
    datasetTitle: 'Test: Andre toiletter (MTM), Aarhus Kommune',
)]
final class TestToiletOther extends AbstractToiletOther
{
}
