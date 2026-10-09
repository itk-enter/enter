<?php

declare(strict_types=1);

namespace App\Source\Osm;

use App\Geo\Wgs84Transformer;
use App\Ngsi\NgsiEntity;
use App\Source\DataType;
use App\Source\Definition;

/**
 * Street-side parking with bays reserved for disabled parking in Aarhus
 * Municipality.
 */
#[Definition(
    id: 'osm-handicap-parking-on-street',
    title: 'Handicapparkering på gaden (OpenStreetMap), Aarhus Kommune',
    description: 'Parking on or beside the street mapped in OpenStreetMap within Aarhus Municipality, with some number of bays reserved for disabled parking. Sites the map does not place on or off the street are published here.',
    publisher: 'OpenStreetMap contributors',
    contact: 'https://community.openstreetmap.org/',
    landingPage: 'https://wiki.openstreetmap.org/wiki/Key:capacity:disabled',
    // The Overpass QL in the URL: within Aarhus Municipality (OSM
    // relation 1784663), select every element tagged as a disabled
    // parking space (parking_space=disabled) or as reserving bays for
    // disabled parking (capacity:disabled, excluding "no" and "0").
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
    model: self::ON_STREET_PARKING,
    contextUrl: 'https://raw.githubusercontent.com/smart-data-models/dataModel.Parking/master/context.jsonld',
    updateFrequency: 'continuous',
    licence: 'https://opendatacommons.org/licenses/odbl/1-0/',

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
    dataset: self::DATASET,
    datasetTitle: self::DATASET_TITLE,
)]
final class OnStreetParking extends AbstractHandicapParking
{
    protected function supports(array $tags): bool
    {
        // A site whose record does not say where it lies is kept on the street.
        return !$this->isSingleBay($tags) && 'offStreet' !== $this->siting($tags);
    }

    protected function getModel(array $tags): string
    {
        return self::ON_STREET_PARKING;
    }

    protected function getKey(array $element): string
    {
        $type = $element['type'] ?? null;
        $id = $element['id'] ?? null;

        // OSM ids are only unique per element type, so both are needed to
        // address the same object again on the next import.
        return \is_string($type) && \is_int($id) ? \sprintf('%s-%d', $type, $id) : '';
    }

    protected function buildNgsiEntity(array $element, array $tags, array $geometry, Wgs84Transformer $transformer): NgsiEntity
    {
        return parent::buildNgsiEntity($element, $tags, $geometry, $transformer)
            ->setProperty('category', $this->siteCategory($tags))
            ->setProperty('totalSpotNumber', $this->reservedBays($tags))
            ->setProperty('parkingMode', $this->parkingMode($tags))
            ->additionalInformation([
                'surface' => trim((string) ($tags['surface'] ?? '')),
                'wheelchair' => trim((string) ($tags['wheelchair'] ?? '')),
            ]);
    }
}
