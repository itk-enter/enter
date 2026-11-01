<?php

declare(strict_types=1);

namespace App\Test\Source\MtmGmPark;

use App\Source\DataType;
use App\Source\MtmGmPark\AbstractBench;
use App\Test\Source\TestDefinition;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Benches in the parks, forests and streets of Aarhus Municipality.
 */
#[When('dev')]
#[When('test')]
#[TestDefinition(
    id: 'test:mtm_gmpark-bench',
    title: 'Test: Bænke (MTM), Aarhus Kommune',
    accessUrl: 'http://nginx:8080/test/data/wfs-ext.gmpark.dk/geoserver/ows?mtm_gmpark-bench',
    dataType: DataType::GeoJSON,
    mediaType: 'application/geo+json',
    crs: 'EPSG:4326',
    model: 'Bench',
    contextUrl: 'https://raw.githubusercontent.com/itk-enter/data-models/Bench/v0.0.1/dataModel.PointOfInterest/context.jsonld',
    omittedFields: self::OMITTED_FIELDS,
    dataUrlBase: 'https://wfs-ext.gmpark.dk/geoserver/ws_2b1b3726-07ea-4872-bda9-6982d717dc2c/ows?service=WFS&version=1.0.0&request=GetFeature&typeName=ws_2b1b3726-07ea-4872-bda9-6982d717dc2c:ud4&outputFormat=application/json',
    dataset: 'test:mtm_gmpark-bench',
    datasetTitle: 'Test: Bænke (MTM), Aarhus Kommune',
)]
final class TestBench extends AbstractBench
{
    protected function twoSided(): ?bool
    {
        return null;
    }
}
