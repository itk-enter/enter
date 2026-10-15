<?php

declare(strict_types=1);

namespace App\Test\Source\Osm;

use App\Geo\Wgs84Transformer;
use App\Ngsi\NgsiEntity;
use App\Source\AbstractSource;
use App\Source\DataType;
use App\Test\Source\TestDefinition;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Disabled parking bays in Aarhus Municipality.
 */
#[When('dev')]
#[When('test')]
#[TestDefinition(
    // By convention the ID as a test source must start with `test:`
    id: 'test:osm-handicap-parking',
    title: 'Test: Handicapparkering (OpenStreetMap), Aarhus Kommune',
    // The Overpass QL in the URL: within Aarhus Municipality (OSM
    // relation 1784663), select every element tagged as a disabled
    // parking space (parking_space=disabled) or as reserving bays for
    // disabled parking (capacity:disabled, excluding "no" and "0").
    accessUrl: 'http://nginx:8080/test/data/overpass-api.de/api/interpreter?osm-handicap-parking',
    dataType: DataType::Overpass,
    mediaType: 'application/json',
    crs: 'EPSG:4326',
    model: 'OnStreetParking',
    contextUrl: 'https://raw.githubusercontent.com/smart-data-models/dataModel.Parking/master/context.jsonld',

    omittedFields: [
        'amenity' => 'Selector distinguishing a single bay (parking_space) from a facility (parking); the model carries no such distinction.',
        'capacity' => 'Published for single bays only; on a facility it counts all bays and would overstate the reserved capacity.',
        'parking' => 'Facility siting (street_side, surface, underground); every record is published under the one model this source names.',
        'orientation' => 'How bays lie relative to the road; the model has no counterpart.',
        'disabled' => 'Access restriction on street-side parking; redundant with the category every entity is published with.',
        'access' => 'Who may enter; mapping it onto permit attributes needs an interpretation the tag values do not support.',
        'fee:conditional' => 'Time-qualified refinement of fee; the category values the plain fee tag maps onto carry no schedule.',
        'surface' => 'Paving material; the model has no counterpart. 6% of records carry it.',
        'wheelchair' => 'Step-free access to the place, not the parking capacity. 3% of records carry it.',
        'capacity:charging' => 'Bays with charging points; a different subset than the reserved bays this data set publishes. 2% of records carry it.',
        'operator' => 'Who runs the facility; a fact about the business rather than its reserved bays. 1% of records carry it.',
        'brand' => 'Commercial brand of the facility; the name already identifies it. Under 1% of records carry it.',
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
    ]
)]
final class TestHandicapParking extends AbstractSource
{
    /**
     * Maps one feed record onto an NgsiEntity.
     *
     * @param array<string, mixed> $data Overpass JSON element
     */
    public function createNgsiEntity(array $data, Wgs84Transformer $transformer): ?NgsiEntity
    {
        $type = $data['type'] ?? null;
        $id = $data['id'] ?? null;

        // OSM ids are only unique per element type, so both are needed to
        // address the same object again on the next import.
        if (!\is_string($type) || !\is_int($id)) {
            return null;
        }

        $geometry = $this->geometry($data);
        if (null === $geometry) {
            return null;
        }

        $tags = \is_array($data['tags'] ?? null) ? $data['tags'] : [];

        $entity = new NgsiEntity(
            \sprintf('urn:ngsi-ld:%s:aarhus-handicap-osm-%s-%d', $this->definition->model, $type, $id),
            $this->definition->model
        );

        return $entity
            ->setProperty('name', trim((string) ($tags['name'] ?? '')))
            ->setProperty('description', trim((string) ($tags['description'] ?? '')))
            ->setProperty('category', $this->category($tags))
            ->setProperty('totalSpotNumber', $this->reservedBays($tags))
            ->setProperty('source', $this->definition->accessUrl)
            ->geoProperty('location', $transformer->transformGeometry($this->definition->crs, $geometry));
    }

    /**
     * Every record is disabled parking; the fee tag refines that with the
     * model's charging categories. Only its two plain values map — an
     * untagged or unrecognised value states nothing about charging rather
     * than assuming free.
     *
     * @param array<string, mixed> $tags
     *
     * @return list<string>
     */
    private function category(array $tags): array
    {
        return match ($tags['fee'] ?? null) {
            'yes' => ['forDisabled', 'feeCharged'],
            'no' => ['forDisabled', 'free'],
            default => ['forDisabled'],
        };
    }

    /**
     * Number of reserved bays the record carries.
     *
     * capacity:disabled counts them directly whatever the record is. A single
     * bay (parking_space=disabled) is reserved in its entirety, so its own
     * capacity applies — one when untagged, per the tag's definition. A
     * facility's plain capacity counts all its bays and is never used, and
     * capacity:disabled=yes states that reserved bays exist without counting
     * them, so nothing is published for it.
     *
     * @param array<string, mixed> $tags
     */
    private function reservedBays(array $tags): ?int
    {
        if (null !== $count = $this->count($tags, 'capacity:disabled')) {
            return $count;
        }

        if ('disabled' === ($tags['parking_space'] ?? null)) {
            return $this->count($tags, 'capacity') ?? 1;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $tags
     */
    private function count(array $tags, string $tag): ?int
    {
        $value = $tags[$tag] ?? null;

        return \is_string($value) && ctype_digit($value) ? (int) $value : null;
    }

    /**
     * @param array<string, mixed> $element
     *
     * @return array{type: string, coordinates: mixed}|null
     */
    private function geometry(array $element): ?array
    {
        return match ($element['type'] ?? null) {
            'node' => $this->point($element['lon'] ?? null, $element['lat'] ?? null),
            'way' => $this->wayGeometry($element['geometry'] ?? null),
            // The feed's output mode carries no member geometry for
            // relations, only their bounding box, so the centre of that box
            // is the best location available.
            'relation' => $this->boundsCentre($element['bounds'] ?? null),
            default => null,
        };
    }

    /**
     * @return array{type: string, coordinates: array{float, float}}|null
     */
    private function point(mixed $longitude, mixed $latitude): ?array
    {
        if (!is_numeric($longitude) || !is_numeric($latitude)) {
            return null;
        }

        return ['type' => 'Point', 'coordinates' => [(float) $longitude, (float) $latitude]];
    }

    /**
     * @return array{type: string, coordinates: mixed}|null
     */
    private function wayGeometry(mixed $vertices): ?array
    {
        if (!\is_array($vertices)) {
            return null;
        }

        $positions = [];
        foreach ($vertices as $vertex) {
            if (!\is_array($vertex) || !is_numeric($vertex['lon'] ?? null) || !is_numeric($vertex['lat'] ?? null)) {
                return null;
            }

            $positions[] = [(float) $vertex['lon'], (float) $vertex['lat']];
        }

        // A way that returns to its first vertex outlines an area — here a
        // bay or a parking lot — so it becomes a Polygon ring rather than a
        // line along its edge. Four positions are a ring's minimum: three
        // corners plus the repeated first.
        if (\count($positions) >= 4 && $positions[0] === $positions[array_key_last($positions)]) {
            return ['type' => 'Polygon', 'coordinates' => [$positions]];
        }

        if (\count($positions) >= 2) {
            return ['type' => 'LineString', 'coordinates' => $positions];
        }

        return null;
    }

    /**
     * @return array{type: string, coordinates: array{float, float}}|null
     */
    private function boundsCentre(mixed $bounds): ?array
    {
        if (!\is_array($bounds)) {
            return null;
        }

        foreach (['minlon', 'minlat', 'maxlon', 'maxlat'] as $edge) {
            if (!is_numeric($bounds[$edge] ?? null)) {
                return null;
            }
        }

        return $this->point(
            ((float) $bounds['minlon'] + (float) $bounds['maxlon']) / 2,
            ((float) $bounds['minlat'] + (float) $bounds['maxlat']) / 2
        );
    }
}
