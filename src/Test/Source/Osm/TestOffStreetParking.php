<?php

declare(strict_types=1);

namespace App\Test\Source\Osm;

use App\Ngsi\NgsiEntity;
use App\Source\DataType;
use App\Test\Source\TestDefinition;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Parking facilities off the street with bays reserved for disabled parking
 * in Aarhus Municipality.
 */
#[When('dev')]
#[When('test')]
#[TestDefinition(
    // By convention the ID as a test source must start with `test:`
    id: 'test:osm-handicap-parking-off-street',
    title: 'Test: Handicapparkering uden for gaden (OpenStreetMap), Aarhus Kommune',
    // The Overpass QL in the URL: within Aarhus Municipality (OSM
    // relation 1784663), select every element tagged as a disabled
    // parking space (parking_space=disabled) or as reserving bays for
    // disabled parking (capacity:disabled, excluding "no" and "0").
    accessUrl: 'http://nginx:8080/test/data/overpass-api.de/api/interpreter?osm-handicap-parking',
    dataType: DataType::Overpass,
    mediaType: 'application/json',
    crs: 'EPSG:4326',
    model: self::OFF_STREET_PARKING,
    contextUrl: 'https://raw.githubusercontent.com/smart-data-models/dataModel.Parking/master/context.jsonld',

    omittedFields: [
        'amenity' => 'Selector distinguishing a parking space from a parking area; what a record holds decides its model, so the tag adds nothing.',
        'capacity' => 'Decides whether a record is a single bay, but a facility\'s capacity counts all its bays and would overstate the reserved ones.',
        'parking' => 'Siting on or off the street, which decides the site model; the kinds of facility the category has a value for are published there.',
        'disabled' => 'Access restriction on street-side parking; redundant with the category every site is published with.',
        'access' => 'Who may enter; mapping it onto permit attributes needs an interpretation the tag values do not support.',
        'fee:conditional' => 'Time-qualified refinement of fee; the category values the plain fee tag maps onto carry no schedule.',
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
    dataset: self::DATASET,
    datasetTitle: self::DATASET_TITLE,
)]
final class TestOffStreetParking extends AbstractTestHandicapParking
{
    /**
     * Values of the parking tag that name a kind of facility the model's
     * category has a value for.
     */
    private const array FACILITY_CATEGORY = [
        'surface' => 'parkingLot',
        'underground' => 'underground',
        'multi-storey' => 'parkingGarage',
    ];

    protected function supports(array $tags): bool
    {
        return !$this->isSingleBay($tags) && 'offStreet' === $this->siting($tags);
    }

    protected function describe(NgsiEntity $entity, array $tags): NgsiEntity
    {
        $parkingMode = $this->parkingMode($tags);

        return $entity
            ->setProperty('category', [...$this->siteCategory($tags), ...$this->facilityCategory($tags)])
            ->setProperty('totalSpotNumber', $this->reservedBays($tags))
            // The off-street model takes a list where the on-street one
            // takes a single value.
            ->setProperty('parkingMode', null === $parkingMode ? null : [$parkingMode])
            ->additionalInformation([
                'surface' => trim((string) ($tags['surface'] ?? '')),
                'wheelchair' => trim((string) ($tags['wheelchair'] ?? '')),
            ]);
    }

    /**
     * The kind of facility, as the parking tag states it; none when the tag
     * names a kind the category has no value for.
     *
     * @param array<string, mixed> $tags
     *
     * @return list<string>
     */
    private function facilityCategory(array $tags): array
    {
        $parking = $tags['parking'] ?? null;

        return \is_string($parking) && isset(self::FACILITY_CATEGORY[$parking]) ? [self::FACILITY_CATEGORY[$parking]] : [];
    }
}
