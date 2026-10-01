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
 * Disabled parking in Aarhus Municipality.
 *
 * The feed mixes two kinds of record: a single reserved bay, and a site with
 * some number of reserved bays. Each is published under the model that
 * describes it, as decided by what the record holds rather than how it is
 * tagged: a mapper draws a bay either as a parking space or as a parking
 * area of one reserved bay.
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
    models: ['ParkingSpot', 'OnStreetParking', 'OffStreetParking'],
    contextUrl: 'https://raw.githubusercontent.com/smart-data-models/dataModel.Parking/master/context.jsonld',

    omittedFields: [
        'amenity' => 'Selector distinguishing a parking space from a parking area; what a record holds decides its model, so the tag adds nothing.',
        'capacity' => 'Decides whether a record is a single bay and is published for one, but a facility\'s capacity counts all its bays and would overstate the reserved ones.',
        'orientation' => 'Published as parkingMode on a site; a bay has no counterpart for it.',
        'disabled' => 'Access restriction on street-side parking; redundant with the category every site is published with.',
        'access' => 'Who may enter; mapping it onto permit attributes needs an interpretation the tag values do not support.',
        'fee:conditional' => 'Time-qualified refinement of fee; the category values the plain fee tag maps onto carry no schedule.',
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
    ],
)]
final class TestHandicapParking extends AbstractSource
{
    private const string PARKING_SPOT = 'ParkingSpot';
    private const string ON_STREET_PARKING = 'OnStreetParking';
    private const string OFF_STREET_PARKING = 'OffStreetParking';

    /**
     * parking=* values placing a site on or beside the carriageway. Every
     * other value (surface, underground, multi-storey, rooftop, …) places
     * it off the street.
     */
    private const array ON_STREET_SITING = ['street_side', 'lane', 'layby', 'on_kerb', 'half_on_kerb', 'shoulder', 'street'];

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
        $model = $this->model($tags);

        $entity = (new NgsiEntity(
            \sprintf('urn:ngsi-ld:%s:aarhus-handicap-osm-%s-%d', $model, $type, $id),
            $model
        ))
            ->setProperty('name', trim((string) ($tags['name'] ?? '')))
            ->setProperty('description', trim((string) ($tags['description'] ?? '')))
            ->setProperty('source', $this->definition->accessUrlWithQuery())
            ->geoProperty('location', $transformer->transformGeometry($this->definition->crs, $geometry));

        if (self::PARKING_SPOT === $model) {
            return $entity
                // The model requires an occupancy status.
                ->setProperty('status', 'unknown')
                ->setProperty('category', $this->spotCategory($tags))

                // A bay has no category for charging, so the fee tag is
                // carried as the feed states it.
                ->additionalInformation([
                    'fee' => trim((string) ($tags['fee'] ?? '')),
                    'surface' => trim((string) ($tags['surface'] ?? '')),
                    'wheelchair' => trim((string) ($tags['wheelchair'] ?? '')),
                ]);
        }

        return $entity
            ->setProperty('category', $this->siteCategory($tags))
            ->setProperty('totalSpotNumber', $this->reservedBays($tags))
            ->setProperty('parkingMode', $this->parkingMode($tags, $model))
            ->additionalInformation([
                'surface' => trim((string) ($tags['surface'] ?? '')),
                'wheelchair' => trim((string) ($tags['wheelchair'] ?? '')),
            ]);
    }

    /**
     * The model a record is published under.
     *
     * A record holding exactly one reserved bay and nothing else is that bay,
     * whether the mapper drew it as a parking space or as a parking area
     * whose whole capacity is the reserved bay. Anything else is a site with
     * reserved bays: a facility, or a row of bays drawn as one object. A
     * site's model follows its siting; where the feed states none, the
     * record keeps the model this source published every record under
     * before records were sorted.
     *
     * @param array<string, mixed> $tags
     */
    private function model(array $tags): string
    {
        if ($this->isSingleBay($tags)) {
            return self::PARKING_SPOT;
        }

        return match ($this->siting($tags)) {
            'offStreet' => self::OFF_STREET_PARKING,
            default => self::ON_STREET_PARKING,
        };
    }

    /**
     * A parking space is reserved in its entirety, so it is one bay unless
     * its capacity says more. A parking area is one bay only when its
     * capacity is stated and is the one reserved bay; a facility of unknown
     * size with one reserved bay is still a facility.
     *
     * @param array<string, mixed> $tags
     */
    private function isSingleBay(array $tags): bool
    {
        if (1 !== $this->reservedBays($tags)) {
            return false;
        }

        $capacity = $this->count($tags, 'capacity');

        return 1 === $capacity || (null === $capacity && 'disabled' === ($tags['parking_space'] ?? null));
    }

    /**
     * On or off the street, as the parking tag states it; null when the feed
     * does not say. A parking space carries no such tag of its own.
     *
     * @param array<string, mixed> $tags
     */
    private function siting(array $tags): ?string
    {
        $parking = $tags['parking'] ?? null;

        if (!\is_string($parking) || '' === $parking) {
            return null;
        }

        return \in_array($parking, self::ON_STREET_SITING, true) ? 'onStreet' : 'offStreet';
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
    private function spotCategory(array $tags): ?array
    {
        $siting = $this->siting($tags);

        return null === $siting ? null : [$siting];
    }

    /**
     * Every site is disabled parking; the fee tag refines that with the
     * model's charging categories. Only its two plain values map — an
     * untagged or unrecognised value states nothing about charging rather
     * than assuming free.
     *
     * @param array<string, mixed> $tags
     *
     * @return list<string>
     */
    private function siteCategory(array $tags): array
    {
        return match ($tags['fee'] ?? null) {
            'yes' => ['forDisabled', 'feeCharged'],
            'no' => ['forDisabled', 'free'],
            default => ['forDisabled'],
        };
    }

    /**
     * How the bays lie relative to the road. The two site models spell the
     * attribute differently: a single value on the street, a list off it.
     *
     * @param array<string, mixed> $tags
     *
     * @return string|list<string>|null
     */
    private function parkingMode(array $tags, string $model): string|array|null
    {
        $mode = match ($tags['orientation'] ?? null) {
            'parallel' => 'parallelParking',
            'perpendicular' => 'perpendicularParking',
            'diagonal' => 'echelonParking',
            default => null,
        };

        if (null === $mode) {
            return null;
        }

        return self::OFF_STREET_PARKING === $model ? [$mode] : $mode;
    }

    /**
     * Number of reserved bays the record carries.
     *
     * capacity:disabled counts them directly whatever the record is. A
     * parking space (parking_space=disabled) is reserved in its entirety, so
     * its own capacity applies — one when untagged, per the tag's definition.
     * A facility's plain capacity counts all its bays and is never used, and
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
