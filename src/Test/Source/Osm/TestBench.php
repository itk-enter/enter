<?php

declare(strict_types=1);

namespace App\Test\Source\Osm;

use App\Source\DataType;
use App\Source\Osm\AbstractBench;
use App\Test\Source\TestDefinition;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Public benches in Aarhus Municipality.
 */
#[When('dev')]
#[When('test')]
#[TestDefinition(
    id: 'test:osm-bench',
    title: 'Test: Bænke (OpenStreetMap), Aarhus Kommune',
    accessUrl: 'http://nginx:8080/test/data/overpass-api.de/api/interpreter?osm-bench',
    dataType: DataType::Overpass,
    mediaType: 'application/json',
    crs: 'EPSG:4326',
    model: 'Bench',
    contextUrl: 'https://raw.githubusercontent.com/itk-enter/data-models/Bench/v0.0.1/dataModel.PointOfInterest/context.jsonld',
    omittedFields: [
        'amenity' => 'Selector; every record is published under the one model this source names.',
        ...self::OMITTED_FIELDS,
    ],
    dataUrlBase: 'https://overpass-api.de/api/interpreter',
    dataUrlQuery: [
        'data' => <<<'DATA'
[out:json][timeout:180];
area(3601784663)->.a;
nwr["amenity"="bench"](area.a);
out center tags;
DATA,
    ],
    dataset: 'test:osm-bench',
    datasetTitle: 'Test: Bænke (OpenStreetMap), Aarhus Kommune',
)]
final class TestBench extends AbstractBench
{
    protected function selectorTag(): string
    {
        return 'amenity';
    }
}
