<?php

declare(strict_types=1);

namespace App\Source\Osm;

use App\Geo\Wgs84Transformer;
use App\Ngsi\NgsiEntity;
use App\Source\AbstractSource;

/**
 * Disabled parking in Aarhus Municipality.
 */
abstract class AbstractHandicapParking extends AbstractSource
{
    protected const string PARKING_SPOT = 'ParkingSpot';
    protected const string ON_STREET_PARKING = 'OnStreetParking';
    protected const string OFF_STREET_PARKING = 'OffStreetParking';

    protected const string DATASET = 'osm-handicap-parking';
    protected const string DATASET_TITLE = 'Handicapparkering (OpenStreetMap), Aarhus Kommune';

    /**
     * The parking tag values that put a site on the street.
     */
    private const array ON_STREET_SITING = ['street_side', 'lane', 'layby', 'on_kerb', 'half_on_kerb', 'shoulder', 'street'];

    /**
     * Makes an NgsiEntity from one feed record, or returns null for other models.
     *
     * @param array<string, mixed> $data Overpass JSON element
     */
    final public function createNgsiEntity(array $data, Wgs84Transformer $transformer): ?NgsiEntity
    {
        $tags = \is_array($data['tags'] ?? null) ? $data['tags'] : [];

        if (!$this->supports($tags)) {
            return null;
        }

        if ('' === $this->getKey($data)) {
            return null;
        }

        $geometry = $this->geometry($data);
        if (null === $geometry) {
            return null;
        }

        return $this->buildNgsiEntity($data, $tags, $geometry, $transformer);
    }

    /**
     * Tells if a record belongs to the model of this source.
     *
     * @param array<string, mixed> $tags
     */
    abstract protected function supports(array $tags): bool;

    /**
     * Gets the model for the record.
     *
     * @param array<string, mixed> $tags
     */
    abstract protected function getModel(array $tags): string;

    /**
     * Gets the stable key of the element, or '' if it has no key.
     *
     * @param array<string, mixed> $element Overpass JSON element
     */
    abstract protected function getKey(array $element): string;

    /**
     * Makes an entity with the properties that all models share.
     *
     * @param array<string, mixed>                    $element  Overpass JSON element
     * @param array<string, mixed>                    $tags
     * @param array{type: string, coordinates: mixed} $geometry
     */
    protected function buildNgsiEntity(array $element, array $tags, array $geometry, Wgs84Transformer $transformer): NgsiEntity
    {
        $model = $this->getModel($tags);

        return new NgsiEntity(
            \sprintf('urn:ngsi-ld:%s:aarhus-handicap-osm-%s', $model, $this->getKey($element)),
            $model
        )
            ->setProperty('name', trim((string) ($tags['name'] ?? '')))
            ->setProperty('description', trim((string) ($tags['description'] ?? '')))
            ->setProperty('source', $this->definition->accessUrlWithQuery())
            ->geoProperty('location', $transformer->transformGeometry($this->definition->crs, $geometry));
    }

    /**
     * Gets the siting, on or off the street, from the tags.
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
     * Gets the site categories, with the fee status if the record has it.
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
     * Gets the parking mode from the orientation of the bays.
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
     * Gets the number of reserved bays in the record.
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
     * Tells if the record is one bay.
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
     * Gets the tag value as an integer, or null.
     *
     * @param array<string, mixed> $tags
     */
    private function tagAsInt(array $tags, string $tag): ?int
    {
        $value = $tags[$tag] ?? null;

        return \is_string($value) && ctype_digit($value) ? (int) $value : null;
    }

    /**
     * Gets the location of the element as GeoJSON.
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
