<?php

declare(strict_types=1);

namespace App\Source\Osm;

use App\Source\DataType;
use App\Source\Definition;

/**
 * Off-street parking facilities with disabled bays in Aarhus Municipality.
 */
#[Definition(
    id: 'osm-handicap-parking-off-street',
    title: 'Handicapparkering uden for gaden (OpenStreetMap), Aarhus Kommune',
    description: 'Parking facilities off the street — lots, garages, rooftops — mapped in OpenStreetMap within Aarhus Municipality, with some number of bays reserved for disabled parking.',
    publisher: 'OpenStreetMap contributors',
    contact: 'https://community.openstreetmap.org/',
    landingPage: 'https://wiki.openstreetmap.org/wiki/Key:capacity:disabled',
    accessUrl: [
        'url' => 'https://overpass-api.de/api/interpreter',
        'query' => [
            'data' => <<<'DATA'
[out:json][timeout:180];
area(3601784663)->.a;
(
 nwr["parking_space"="disabled"](area.a);
 nwr["capacity:disabled"]["capacity:disabled"!~"^(no|0)$"](area.a);
);
out geom tags;
DATA,
        ],
    ],
    dataType: DataType::Overpass,
    mediaType: 'application/json',
    crs: 'EPSG:4326',
    model: self::OFF_STREET_PARKING,
    contextUrl: 'https://raw.githubusercontent.com/smart-data-models/dataModel.Parking/master/context.jsonld',
    updateFrequency: 'continuous',
    licence: 'https://opendatacommons.org/licenses/odbl/1-0/',

    omittedFields: [
        'amenity' => 'Selector that tells a parking space from a parking area; what a record holds decides its model, so the tag adds nothing.',
        'capacity' => 'Decides whether a record is a single bay, but a facility\'s capacity counts all its bays and would overstate the reserved ones.',
        'parking' => 'Location on or off the street, which decides the site model; the kinds of facility the category has a value for are published there.',
        'disabled' => 'Access restriction on street-side parking; redundant with the category every site is published with.',
        'access' => 'Who may enter; to map it onto permit attributes needs an interpretation the tag values do not support.',
        'fee:conditional' => 'Time-qualified refinement of fee; the category values the plain fee tag maps onto carry no schedule.',
        'capacity:charging' => 'Bays with charging points; a different subset than the reserved bays this data set publishes.',
        'operator' => 'Who runs the facility; a fact about the business rather than its reserved bays.',
        'brand' => 'Commercial brand of the facility; the name already identifies it.',
    ],
    dataset: self::DATASET,
    datasetTitle: self::DATASET_TITLE,
)]
final class OffStreetParking extends AbstractOffStreetParking
{
}
