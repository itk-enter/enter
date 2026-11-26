<?php

declare(strict_types=1);

namespace App\Test\Source\Osm;

use App\Source\DataType;
use App\Source\Osm\AbstractBench;
use App\Test\Source\TestDefinition;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Picnic tables in Aarhus Municipality. People can sit on both sides of the
 * table, unless OpenStreetMap tells otherwise.
 */
#[When('dev')]
#[When('test')]
#[TestDefinition(
    id: 'test:osm-picnic-table',
    title: 'Test: Bordbænkesæt (OpenStreetMap), Aarhus Kommune',
    accessUrl: 'http://nginx:8080/test/data/overpass-api.de/api/interpreter?osm-picnic-table',
    dataType: DataType::Overpass,
    mediaType: 'application/json',
    crs: 'EPSG:4326',
    model: 'Bench',
    contextUrl: 'https://raw.githubusercontent.com/itk-enter/data-models/Bench/v0.0.1/dataModel.PointOfInterest/context.jsonld',
    omittedFields: [
        'leisure' => 'Selector; every record is published under the one model this source names. A record that is also amenity=bench is left to the bench source.',
        ...self::OMITTED_FIELDS,
    ],
    dataUrlBase: 'https://overpass-api.de/api/interpreter',
    dataUrlQuery: [
        'data' => <<<'DATA'
[out:json][timeout:180];
area(3601784663)->.a;
nwr["leisure"="picnic_table"]["amenity"!="bench"](area.a);
out center tags;
DATA,
    ],
    dataset: 'test:osm-picnic-table',
    datasetTitle: 'Test: Bordbænkesæt (OpenStreetMap), Aarhus Kommune',
)]
final class TestPicnicTable extends AbstractBench
{
    protected function kind(): string
    {
        return 'picnicTable';
    }

    protected function selectorTag(): string
    {
        return 'leisure';
    }

    protected function defaultTwoSided(): ?bool
    {
        return true;
    }
}
