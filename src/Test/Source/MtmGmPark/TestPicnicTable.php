<?php

declare(strict_types=1);

namespace App\Test\Source\MtmGmPark;

use App\Source\DataType;
use App\Source\MtmGmPark\AbstractBench;
use App\Test\Source\TestDefinition;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Picnic tables with benches in the parks, forests and streets of Aarhus
 * Municipality. People can sit on both sides of the table.
 */
#[When('dev')]
#[When('test')]
#[TestDefinition(
    id: 'test:mtm_gmpark-picnic-table',
    title: 'Test: Bordbænkesæt (MTM), Aarhus Kommune',
    accessUrl: 'http://nginx:8080/test/data/wfs-ext.gmpark.dk/geoserver/ows?mtm_gmpark-picnic-table',
    dataType: DataType::GeoJSON,
    mediaType: 'application/geo+json',
    crs: 'EPSG:4326',
    model: 'Bench',
    contextUrl: 'https://raw.githubusercontent.com/itk-enter/data-models/Bench/v0.0.1/dataModel.PointOfInterest/context.jsonld',
    omittedFields: self::OMITTED_FIELDS,
    dataUrlBase: 'https://wfs-ext.gmpark.dk/geoserver/ws_2b1b3726-07ea-4872-bda9-6982d717dc2c/ows?service=WFS&version=1.0.0&request=GetFeature&typeName=ws_2b1b3726-07ea-4872-bda9-6982d717dc2c:ud14&outputFormat=application/json',
    dataset: 'test:mtm_gmpark-picnic-table',
    datasetTitle: 'Test: Bordbænkesæt (MTM), Aarhus Kommune',
)]
final class TestPicnicTable extends AbstractBench
{
    protected function twoSided(): ?bool
    {
        return true;
    }
}
