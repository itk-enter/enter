<?php

declare(strict_types=1);

namespace App\Source\Osm;

use App\Geo\Wgs84Transformer;
use App\Ngsi\NgsiEntity;
use App\Source\AbstractSource;

/**
 * Disabled parking in Aarhus Municipality, one source per model.
 *
 * The feed mixes two kinds of record: a single reserved bay, and a site with
 * some number of reserved bays. Each source declares the same feed and
 * keeps only the records of its own model, so the rule that sorts records
 * into models lives here: were it to drift between the sources, a record
 * would be published twice or not at all, and nothing would say so.
 */
abstract class AbstractHandicapParking extends AbstractSource
{
    protected const string PARKING_SPOT = 'ParkingSpot';
    protected const string ON_STREET_PARKING = 'OnStreetParking';
    protected const string OFF_STREET_PARKING = 'OffStreetParking';

    /**
     * parking=* values placing a site on or beside the carriageway. Every
     * other value (surface, underground, multi-storey, rooftop, …) places
     * it off the street.
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

        $model = $this->definition->model();
        if ($this->model($tags) !== $model) {
            return null;
        }

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
     * Sets what only this source's model holds.
     *
     * @param array<string, mixed> $tags
     */
    abstract protected function describe(NgsiEntity $entity, array $tags): NgsiEntity;

    /**
     * On or off the street, as the parking tag states it; null when the feed
     * does not say. A parking space carries no such tag of its own.
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
     * Every site is disabled parking; the fee tag refines that with the
     * site models' charging categories. Only its two plain values map — an
     * untagged or unrecognised value states nothing about charging rather
     * than assuming free.
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
     * capacity:disabled counts them directly whatever the record is. A
     * parking space (parking_space=disabled) is reserved in its entirety, so
     * its own capacity applies — one when untagged, per the tag's definition.
     * A facility's plain capacity counts all its bays and is never used, and
     * capacity:disabled=yes states that reserved bays exist without counting
     * them, so nothing is published for it.
     *
     * @param array<string, mixed> $tags
     */
    final protected function reservedBays(array $tags): ?int
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
     * The model a record is published under.
     *
     * A record holding exactly one reserved bay and nothing else is that bay,
     * whether the mapper drew it as a parking space or as a parking area
     * whose whole capacity is the reserved bay. Anything else is a site with
     * reserved bays: a facility, or a row of bays drawn as one object. A
     * site's model follows its siting; where the feed states none, the
     * record keeps the model every record was published under before
     * records were sorted.
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
