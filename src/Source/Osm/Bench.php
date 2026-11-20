<?php

declare(strict_types=1);

namespace App\Source\Osm;

use App\Source\DataType;
use App\Source\Definition;

/**
 * Public benches in Aarhus Municipality.
 */
#[Definition(
    id: 'osm-bench',
    title: 'Bænke (OpenStreetMap), Aarhus Kommune',
    description: 'Benches mapped in OpenStreetMap within Aarhus Municipality.',
    publisher: 'OpenStreetMap contributors',
    contact: 'https://community.openstreetmap.org/',
    landingPage: 'https://wiki.openstreetmap.org/wiki/Tag:amenity%3Dbench',

    accessUrl: [
        'url' => 'https://overpass-api.de/api/interpreter',
        'query' => [
            'data' => <<<'DATA'
[out:json][timeout:180];
area(3601784663)->.a;
nwr["amenity"="bench"](area.a);
out center tags;
DATA,
        ],
    ],
    dataType: DataType::Overpass,
    mediaType: 'application/json',
    crs: 'EPSG:4326',
    model: 'Bench',
    contextUrl: 'https://raw.githubusercontent.com/itk-enter/data-models/Bench/v0.0.1/dataModel.PointOfInterest/context.jsonld',
    updateFrequency: 'continuous',
    licence: 'https://opendatacommons.org/licenses/odbl/1-0/',

    omittedFields: [
        'amenity' => 'Selector; every record is published under the one model this source names.',
        ...self::OMITTED_FIELDS,
    ],
    dataset: 'osm-bench',
    datasetTitle: 'Bænke (OpenStreetMap), Aarhus Kommune',
)]
final class Bench extends AbstractBench
{
    protected function kind(): string
    {
        return 'bench';
    }

    protected function selectorTag(): string
    {
        return 'amenity';
    }
}
