<?php

declare(strict_types=1);

namespace App\Conflation\Lookup;

use App\GeometryHelper;

/**
 * The location of a merged entity, as the run and the worker compare it and
 * give it to a lookup.
 */
final readonly class Location
{
    /**
     * The decimal places of a coordinate that the comparison uses. The broker
     * can give a coordinate back with a different last digit.
     */
    private const int PRECISION = 9;

    public function __construct(
        private GeometryHelper $geometry,
    ) {
    }

    /**
     * Tests if two GeoJSON geometries are the same, to PRECISION decimal
     * places.
     */
    public static function same(mixed $geometry, mixed $other): bool
    {
        return \is_array($geometry) && \is_array($other) && self::rounded($geometry) === self::rounded($other);
    }

    /**
     * The coordinate that a lookup gets for a geometry: the point itself, or
     * the centroid of another geometry.
     *
     * @param array<string, mixed> $geometry GeoJSON geometry in WGS 84
     *
     * @return array{float, float} the longitude and the latitude
     */
    public function point(array $geometry): array
    {
        $coordinates = $geometry['coordinates'] ?? null;
        if ('Point' === ($geometry['type'] ?? null) && \is_array($coordinates) && is_numeric($coordinates[0] ?? null) && is_numeric($coordinates[1] ?? null)) {
            return [(float) $coordinates[0], (float) $coordinates[1]];
        }

        [$longitude, $latitude] = $this->geometry->getCentroid($geometry)->toArray();

        return [(float) $longitude, (float) $latitude];
    }

    /**
     * The geometry with each coordinate rounded to PRECISION.
     */
    private static function rounded(mixed $value): mixed
    {
        if (\is_int($value) || \is_float($value)) {
            return round((float) $value, self::PRECISION);
        }

        if (\is_array($value)) {
            return array_map(self::rounded(...), $value);
        }

        return $value;
    }
}
