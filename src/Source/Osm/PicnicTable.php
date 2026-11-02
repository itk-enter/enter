<?php

declare(strict_types=1);

namespace App\Source\Osm;

use App\Source\DataType;
use App\Source\Definition;

/**
 * Picnic tables in Aarhus Municipality. People can sit on both sides of the
 * table, unless OpenStreetMap tells otherwise.
 */
#[Definition(
    id: 'osm-picnic-table',
    title: 'Bordbænkesæt (OpenStreetMap), Aarhus Kommune',
    description: 'Picnic tables mapped in OpenStreetMap within Aarhus Municipality.',
    publisher: 'OpenStreetMap contributors',
    contact: 'https://community.openstreetmap.org/',
    landingPage: 'https://wiki.openstreetmap.org/wiki/Tag:leisure%3Dpicnic_table',

    accessUrl: [
        'url' => 'https://overpass-api.de/api/interpreter',
        'query' => [
            'data' => <<<'DATA'
[out:json][timeout:180];
area(3601784663)->.a;
nwr["leisure"="picnic_table"]["amenity"!="bench"](area.a);
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
        'leisure' => 'Selector; every record is published under the one model this source names. A record that is also amenity=bench is left to the bench source.',
        ...self::OMITTED_FIELDS,
    ],
    dataset: 'osm-picnic-table',
    datasetTitle: 'Bordbænkesæt (OpenStreetMap), Aarhus Kommune',
)]
final class PicnicTable extends AbstractBench
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
