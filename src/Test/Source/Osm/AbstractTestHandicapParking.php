<?php

declare(strict_types=1);

namespace App\Test\Source\Osm;

use App\Geo\Wgs84Transformer;
use App\Ngsi\NgsiEntity;
use App\Source\AbstractSource;

/**
 * Disabled parking in Aarhus Municipality.
 */
abstract class AbstractTestHandicapParking extends AbstractSource
{
    // Models that this source produces.
    protected const string PARKING_SPOT = 'ParkingSpot';
    protected const string ON_STREET_PARKING = 'OnStreetParking';
    protected const string OFF_STREET_PARKING = 'OffStreetParking';

    // Dataset reference.
    protected const string DATASET = 'test:osm-handicap-parking';
    protected const string DATASET_TITLE = 'Test: Handicapparkering (OpenStreetMap), Aarhus Kommune';

    /**
     * OSM tags used to define if a parkingSpot is on or off the street.
     */
    private const array ON_STREET_SITING = ['street_side', 'lane', 'layby', 'on_kerb', 'half_on_kerb', 'shoulder', 'street'];

    /**
     * Maps one feed record onto an NgsiEntity, or null when the record
     * belongs to one of the other models.
     *
     * @param array<string, mixed> $data Overpass JSON element
     */
    final public function createNgsiEntity(array $data, Wgs84Transformer $transformer): ?NgsiEntity
    {
        $type = $data['type'] ?? null;
        $id = $data['id'] ?? null;

        // OSM ids are only unique per element type, so both are needed to
        // address the same object again on the next import.
        if (!\is_string($type) || !\is_int($id)) {
            return null;
        }

        $tags = \is_array($data['tags'] ?? null) ? $data['tags'] : [];

        if (!$this->supports($tags)) {
            return null;
        }

        $model = $this->definition->model;

        $geometry = $this->geometry($data);
        if (null === $geometry) {
            return null;
        }

        $entity = new NgsiEntity(
            \sprintf('urn:ngsi-ld:%s:aarhus-handicap-osm-%s-%d', $model, $type, $id),
            $model
        )
            ->setProperty('name', trim((string) ($tags['name'] ?? '')))
            ->setProperty('description', trim((string) ($tags['description'] ?? '')))
            ->setProperty('source', $this->definition->accessUrlWithQuery())
            ->geoProperty('location', $transformer->transformGeometry($this->definition->crs, $geometry));

        return $this->describe($entity, $tags);
    }

    /**
     * Whether a record belongs to this source's model.
     *
     * @param array<string, mixed> $tags
     */
    abstract protected function supports(array $tags): bool;

    /**
     * Sets what only this source's model holds.
     *
     * @param array<string, mixed> $tags
     */
    abstract protected function describe(NgsiEntity $entity, array $tags): NgsiEntity;

    /**
     * On or off the street, deduced from OSM tags.
     *
     * @param array<string, mixed> $tags
     */
    final protected function siting(array $tags): ?string
    {
        $parking = $tags['parking'] ?? null;

        if (!\is_string($parking) || '' === $parking) {
            return null;
        }

        return \in_array($parking, self::ON_STREET_SITING, true) ? 'onStreet' : 'offStreet';
    }

    /**
     * Define whether the spot has a fee, only if the source defines it.
     *
     * @param array<string, mixed> $tags
     *
     * @return list<string>
     */
    final protected function siteCategory(array $tags): array
    {
        return match ($tags['fee'] ?? null) {
            'yes' => ['forDisabled', 'feeCharged'],
            'no' => ['forDisabled', 'free'],
            default => ['forDisabled'],
        };
    }

    /**
     * How a site's bays lie relative to the road.
     *
     * @param array<string, mixed> $tags
     */
    final protected function parkingMode(array $tags): ?string
    {
        return match ($tags['orientation'] ?? null) {
            'parallel' => 'parallelParking',
            'perpendicular' => 'perpendicularParking',
            'diagonal' => 'echelonParking',
            default => null,
        };
    }

    /**
     * Number of reserved bays the record carries.
     *
     * @param array<string, mixed> $tags
     */
    final protected function reservedBays(array $tags): ?int
    {
        if (null !== $count = $this->tagAsInt($tags, 'capacity:disabled')) {
            return $count;
        }

        if ('disabled' === ($tags['parking_space'] ?? null)) {
            return $this->tagAsInt($tags, 'capacity') ?? 1;
        }

        return null;
    }

    /**
     * One bay unless its capacity says more.
     *
     * @param array<string, mixed> $tags
     */
    final protected function isSingleBay(array $tags): bool
    {
        if (1 !== $this->reservedBays($tags)) {
            return false;
        }

        $capacity = $this->tagAsInt($tags, 'capacity');

        return 1 === $capacity || (null === $capacity && 'disabled' === ($tags['parking_space'] ?? null));
    }

    /**
     * The tag's value as a whole number, or null when it is not one.
     *
     * @param array<string, mixed> $tags
     */
    private function tagAsInt(array $tags, string $tag): ?int
    {
        $value = $tags[$tag] ?? null;

        return \is_string($value) && ctype_digit($value) ? (int) $value : null;
    }

    /**
     * The element's location as GeoJSON. OSM has three kinds of element,
     * and the feed gives each its location in its own shape: a node as a
     * single coordinate, a way as its list of vertices, and a relation only
     * as a bounding box.
     *
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
