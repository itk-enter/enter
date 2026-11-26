<?php

declare(strict_types=1);

namespace App\Test\Source\MtmSpatialMaps;

use App\Source\DataType;
use App\Source\MtmSpatialMaps\AbstractToiletCity;
use App\Test\Source\TestDefinition;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * City-kiosk public toilets in Aarhus Municipality.
 */
#[When('dev')]
#[When('test')]
#[TestDefinition(
    id: 'test:mtm_spatialmaps-toilet-city',
    title: 'Test: Bytoiletter (MTM), Aarhus Kommune',
    accessUrl: 'http://nginx:8080/test/data/webkort.aarhuskommune.dk/spatialmap?mtm_spatialmaps-toilet-city',
    dataType: DataType::GeoJSON,
    mediaType: 'application/geo+json',
    crs: 'EPSG:25832',
    model: 'PublicToilet',
    contextUrl: 'https://raw.githubusercontent.com/itk-enter/data-models/PublicToilet/v0.0.2/dataModel.PointOfInterest/context.jsonld',
    omittedFields: [
        'familie' => 'Category designation; constant "Toilet" throughout the export, redundant with the model every entity is published under.',
        'subfamilie' => 'Product designation; constant "TOI Cox" throughout the export.',
        'kommune' => 'Constant "Aarhus" throughout the export, the data set\'s own scope.',
        'distrikt' => 'Internal municipal maintenance district, not a fact about the toilet.',
        'northing' => 'Stated in a different, unlabelled projection than the primary geometry and does not agree with it once reprojected; frequently absent.',
        'easting_westing' => 'Stated in a different, unlabelled projection than the primary geometry and does not agree with it once reprojected; frequently absent.',
        'oprettet_af' => 'Directory username of the municipal employee who created the record; personal data, and not a fact about the toilet.',
        'rettet_af' => 'Directory username of the municipal employee who last edited the record; personal data, and not a fact about the toilet.',
        'mi_style' => 'MapInfo rendering style.',
    ],
    dataUrlBase: 'https://webkort.aarhuskommune.dk/spatialmap?page=get_geojson_opendata&datasource=by_toiletter',
    dataset: 'test:mtm_spatialmaps-toilet-city',
    datasetTitle: 'Test: Bytoiletter (MTM), Aarhus Kommune',
)]
final class TestToiletCity extends AbstractToiletCity
{
}
