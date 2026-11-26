<?php

declare(strict_types=1);

namespace App\Source\Osm;

use App\Source\DataType;
use App\Source\Definition;

/**
 * Public toilets in Aarhus Municipality.
 */
#[Definition(
    id: 'osm-public-toilet',
    title: 'Offentlige toiletter (OpenStreetMap), Aarhus Kommune',
    description: 'Public toilets mapped in OpenStreetMap within Aarhus Municipality, with the wheelchair access they state.',
    publisher: 'OpenStreetMap contributors',
    contact: 'https://community.openstreetmap.org/',
    landingPage: 'https://wiki.openstreetmap.org/wiki/Tag:amenity%3Dtoilets',

    accessUrl: [
        'url' => 'https://overpass-api.de/api/interpreter',
        'query' => [
            'data' => <<<'DATA'
[out:json][timeout:180];
area(3601784663)->.a;
nwr["amenity"="toilets"](area.a);
out center tags;
DATA,
        ],
    ],
    dataType: DataType::Overpass,
    mediaType: 'application/json',
    crs: 'EPSG:4326',
    model: 'PublicToilet',
    contextUrl: 'https://raw.githubusercontent.com/itk-enter/data-models/PublicToilet/v0.0.2/dataModel.PointOfInterest/context.jsonld',
    updateFrequency: 'continuous',
    licence: 'https://opendatacommons.org/licenses/odbl/1-0/',

    omittedFields: [
        'amenity' => 'Selector; every record is published under the one model this source names.',
        'building' => 'States that the toilet occupies a building of its own, and the building tags beside it describe its levels, material and roof; facts about the structure rather than the facility. 20% of records carry it.',
        'check_date' => 'When a mapper last verified the record, as do the check_date qualifiers beside it; describes the survey rather than the toilet. 34% of records carry it.',
        'source' => 'Where a mapper took the record from; describes the work of the mapper, and the source this import records is the feed it read. 1% of records carry it.',
        'note' => 'Free-text remark addressed to other mappers, as is fixme. 2% of records carry it.',
        'fixme' => 'Free-text remark addressed to other mappers.',
        'roof' => 'Describes the building the toilet occupies rather than the facility, as the roof: qualifiers do.',
        'mapillary' => 'Identifier in an external street-imagery service, as is panoramax; a photograph of the place rather than a fact about it. 3% of records carry panoramax and 1% mapillary.',
        'panoramax' => 'Identifier in an external street-imagery service; see mapillary.',
    ],
    dataset: 'osm-public-toilet',
    datasetTitle: 'Offentlige toiletter (OpenStreetMap), Aarhus Kommune',
)]
final class PublicToilet extends AbstractPublicToilet
{
}
