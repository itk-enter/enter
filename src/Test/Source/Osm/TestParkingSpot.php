<?php

declare(strict_types=1);

namespace App\Test\Source\Osm;

use App\Ngsi\NgsiEntity;
use App\Source\DataType;
use App\Test\Source\TestDefinition;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Single disabled parking bays in Aarhus Municipality.
 */
#[When('dev')]
#[When('test')]
#[TestDefinition(
    // By convention the ID as a test source must start with `test:`
    id: 'test:osm-handicap-parking-spot',
    title: 'Test: Handicapparkeringspladser (OpenStreetMap), Aarhus Kommune',
    // The Overpass QL in the URL: within Aarhus Municipality (OSM
    // relation 1784663), select every element tagged as a disabled
    // parking space (parking_space=disabled) or as reserving bays for
    // disabled parking (capacity:disabled, excluding "no" and "0").
    accessUrl: 'http://nginx:8080/test/data/overpass-api.de/api/interpreter?osm-handicap-parking',
    dataType: DataType::Overpass,
    mediaType: 'application/json',
    crs: 'EPSG:4326',
    models: [self::PARKING_SPOT],
    contextUrl: 'https://raw.githubusercontent.com/smart-data-models/dataModel.Parking/master/context.jsonld',

    omittedFields: [
        'amenity' => 'Selector distinguishing a parking space from a parking area; what a record holds decides its model, so the tag adds nothing.',
        'capacity' => 'Decides whether a record is a single bay; for one it is the one bay, and the model counts none.',
        'capacity:disabled' => 'Decides whether a record is a single bay; for one it is the one bay, and the model counts none.',
        'orientation' => 'How the bay lies relative to the road; the model has no counterpart.',
        'disabled' => 'Access restriction on street-side parking; every record in this data set is a reserved bay.',
        'access' => 'Who may enter; mapping it onto permit attributes needs an interpretation the tag values do not support.',
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
)]
final class TestParkingSpot extends AbstractTestHandicapParking
{
    protected function describe(NgsiEntity $entity, array $tags): NgsiEntity
    {
        return $entity
            // The model requires an occupancy status.
            ->setProperty('status', 'unknown')
            ->setProperty('category', $this->category($tags))

            // A bay has no category for charging, so the fee tag is carried
            // as the feed states it.
            ->additionalInformation([
                'fee' => trim((string) ($tags['fee'] ?? '')),
                'surface' => trim((string) ($tags['surface'] ?? '')),
                'wheelchair' => trim((string) ($tags['wheelchair'] ?? '')),
            ]);
    }

    /**
     * The bay model's category is the siting of the site the bay belongs
     * to. It is required by the schema, but a guess would misplace half the
     * bays, so a record the feed does not site is published without it.
     *
     * @param array<string, mixed> $tags
     *
     * @return list<string>|null
     */
    private function category(array $tags): ?array
    {
        $siting = $this->siting($tags);

        return null === $siting ? null : [$siting];
    }
}
