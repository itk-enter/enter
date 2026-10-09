<?php

declare(strict_types=1);

namespace App\Test\Source\Osm;

use App\Ngsi\NgsiEntity;
use App\Source\DataType;
use App\Test\Source\TestDefinition;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Street-side parking with bays reserved for disabled parking in Aarhus
 * Municipality.
 */
#[When('dev')]
#[When('test')]
#[TestDefinition(
    // By convention the ID as a test source must start with `test:`
    id: 'test:osm-handicap-parking-on-street',
    title: 'Test: Handicapparkering på gaden (OpenStreetMap), Aarhus Kommune',
    // The Overpass QL in the URL: within Aarhus Municipality (OSM
    // relation 1784663), select every element tagged as a disabled
    // parking space (parking_space=disabled) or as reserving bays for
    // disabled parking (capacity:disabled, excluding "no" and "0").
    accessUrl: 'http://nginx:8080/test/data/overpass-api.de/api/interpreter?osm-handicap-parking',
    dataType: DataType::Overpass,
    mediaType: 'application/json',
    crs: 'EPSG:4326',
    model: self::ON_STREET_PARKING,
    contextUrl: 'https://raw.githubusercontent.com/smart-data-models/dataModel.Parking/master/context.jsonld',

    omittedFields: [
        'amenity' => 'Selector distinguishing a parking space from a parking area; what a record holds decides its model, so the tag adds nothing.',
        'capacity' => 'Decides whether a record is a single bay, but a site\'s capacity counts all its bays and would overstate the reserved ones.',
        'parking' => 'Siting on or off the street; decides which of the two site models a record is published under.',
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
final class TestOnStreetParking extends AbstractTestHandicapParking
{
    protected function supports(array $tags): bool
    {
        // A site whose record does not say where it lies is kept on the street.
        return !$this->isSingleBay($tags) && 'offStreet' !== $this->siting($tags);
    }

    protected function describe(NgsiEntity $entity, array $tags): NgsiEntity
    {
        return $entity
            ->setProperty('category', $this->siteCategory($tags))
            ->setProperty('totalSpotNumber', $this->reservedBays($tags))
            ->setProperty('parkingMode', $this->parkingMode($tags))
            ->additionalInformation([
                'surface' => trim((string) ($tags['surface'] ?? '')),
                'wheelchair' => trim((string) ($tags['wheelchair'] ?? '')),
            ]);
    }
}
