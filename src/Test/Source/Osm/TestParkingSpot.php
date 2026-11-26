<?php

declare(strict_types=1);

namespace App\Test\Source\Osm;

use App\Source\DataType;
use App\Source\Osm\AbstractParkingSpot;
use App\Test\Source\TestDefinition;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Single disabled parking bays in Aarhus Municipality.
 */
#[When('dev')]
#[When('test')]
#[TestDefinition(
    id: 'test:osm-handicap-parking-spot',
    title: 'Test: Handicapparkeringspladser (OpenStreetMap), Aarhus Kommune',
    accessUrl: 'http://nginx:8080/test/data/overpass-api.de/api/interpreter?osm-handicap-parking',
    dataType: DataType::Overpass,
    mediaType: 'application/json',
    crs: 'EPSG:4326',
    model: self::PARKING_SPOT,
    contextUrl: 'https://raw.githubusercontent.com/smart-data-models/dataModel.Parking/master/context.jsonld',

    omittedFields: [
        'amenity' => 'Selector that tells a parking space from a parking area; what a record holds decides its model, so the tag adds nothing.',
        'capacity' => 'Decides whether a record is a single bay; for one it is the one bay, and the model counts none.',
        'capacity:disabled' => 'Decides whether a record is a single bay; for one it is the one bay, and the model counts none.',
        'orientation' => 'How the bay lies relative to the road; the model has no counterpart.',
        'disabled' => 'Access restriction on street-side parking; every record in this data set is a reserved bay.',
        'access' => 'Who may enter; to map it onto permit attributes needs an interpretation the tag values do not support.',
        'fee:conditional' => 'Time-qualified refinement of fee, which is carried as the feed states it.',
        'capacity:charging' => 'Bays with charging points; a different subset than the reserved bays this data set publishes.',
        'operator' => 'Who runs the facility; a fact about the business rather than its reserved bays.',
        'brand' => 'Commercial brand of the facility; the name already identifies it.',
    ],
    dataUrlBase: 'https://overpass-api.de/api/interpreter',
    dataUrlQuery: [
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
    dataset: 'test:osm-handicap-parking',
    datasetTitle: 'Test: Handicapparkering (OpenStreetMap), Aarhus Kommune',
)]
final class TestParkingSpot extends AbstractParkingSpot
{
}
