<?php

declare(strict_types=1);

namespace App\Conflation\Spatial;

use App\Conflation\Data\Record;

/**
 * Compares a record's point with a GeoJSON geometry in WGS84: whether the
 * point lies inside it, and how far away it lies.
 */
final class Shape
{
    /**
     * How far, in degrees, a point may lie from an area's edge and still count
     * as on it: about 0.1 mm, to absorb rounding in the coordinates.
     */
    private const float EDGE_TOLERANCE = 1e-9;

    /**
     * Whether a point lies inside a Polygon or a MultiPolygon: on its edge
     * included, in its holes excluded. Any other geometry contains no point.
     *
     * @param array<string, mixed> $geometry
     */
    public static function contains(array $geometry, float $longitude, float $latitude): bool
    {
        $polygons = match ($geometry['type'] ?? null) {
            'Polygon' => [$geometry['coordinates']],
            'MultiPolygon' => $geometry['coordinates'],
            default => [],
        };

        /* @var list<list<list<array{0: float|int, 1: float|int}>>> $polygons */
        return array_any($polygons, static fn (array $rings): bool => self::inPolygon($longitude, $latitude, $rings));
    }

    /**
     * The distance, in metres, from a record's point to a geometry: 0 inside
     * an area or on its edge, otherwise the distance to the nearest edge,
     * line or point of the geometry.
     *
     * @param array<string, mixed> $geometry
     */
    public static function distance(Record $point, array $geometry): float
    {
        if (self::contains($geometry, $point->longitude, $point->latitude)) {
            return 0.0;
        }

        $lines = self::lines($geometry);
        if ([] === $lines) {
            throw new \InvalidArgumentException(\sprintf('Cannot measure the distance from %s to a geometry of type %s.', $point->id, get_debug_type($geometry['type'] ?? null)));
        }

        $nearest = INF;
        foreach ($lines as $line) {
            if (1 === \count($line)) {
                $nearest = min($nearest, Distance::between($point, new Record('', '', (float) $line[0][0], (float) $line[0][1])));
                continue;
            }

            for ($i = 1, $count = \count($line); $i < $count; ++$i) {
                $nearest = min($nearest, self::toSegment($point, $line[$i - 1], $line[$i]));
            }
        }

        return $nearest;
    }

    /**
     * The bounding box of a geometry, in degrees.
     *
     * @param array<string, mixed> $geometry
     *
     * @return array{float, float, float, float} the smallest longitude and latitude, then the largest
     */
    public static function bounds(array $geometry): array
    {
        $positions = array_merge(...self::lines($geometry));
        if ([] === $positions) {
            throw new \InvalidArgumentException(\sprintf('Cannot find the bounds of a geometry of type %s.', get_debug_type($geometry['type'] ?? null)));
        }

        $longitudes = array_map(static fn (array $position): float => (float) $position[0], $positions);
        $latitudes = array_map(static fn (array $position): float => (float) $position[1], $positions);

        return [min($longitudes), min($latitudes), max($longitudes), max($latitudes)];
    }

    /**
     * The positions of a geometry as lines: each ring, line string or point
     * on its own, a point as a line of one position.
     *
     * @param array<string, mixed> $geometry
     *
     * @return list<list<array{0: float|int, 1: float|int}>>
     */
    private static function lines(array $geometry): array
    {
        $coordinates = $geometry['coordinates'] ?? null;
        if (!\is_array($coordinates)) {
            return [];
        }

        /* @var list<list<array{0: float|int, 1: float|int}>> */
        return match ($geometry['type'] ?? null) {
            'Point' => [[$coordinates]],
            'MultiPoint' => array_map(static fn (array $position): array => [$position], $coordinates),
            'LineString' => [$coordinates],
            'MultiLineString', 'Polygon' => $coordinates,
            'MultiPolygon' => array_merge(...$coordinates),
            default => [],
        };
    }

    /**
     * The distance, in metres, from a point to a line segment.
     *
     * @param array{0: float|int, 1: float|int} $a
     * @param array{0: float|int, 1: float|int} $b
     */
    private static function toSegment(Record $point, array $a, array $b): float
    {
        // Project the segment onto a plane in metres, with the point at the
        // origin.
        $metresPerLongitude = Distance::METRES_PER_DEGREE * cos(deg2rad($point->latitude));
        $ax = ((float) $a[0] - $point->longitude) * $metresPerLongitude;
        $ay = ((float) $a[1] - $point->latitude) * Distance::METRES_PER_DEGREE;
        $bx = ((float) $b[0] - $point->longitude) * $metresPerLongitude;
        $by = ((float) $b[1] - $point->latitude) * Distance::METRES_PER_DEGREE;

        // Find the position on the segment nearest to the origin.
        $dx = $bx - $ax;
        $dy = $by - $ay;
        $lengthSquared = $dx ** 2 + $dy ** 2;
        $t = 0.0 === $lengthSquared ? 0.0 : max(0.0, min(1.0, -($ax * $dx + $ay * $dy) / $lengthSquared));

        return hypot($ax + $t * $dx, $ay + $t * $dy);
    }

    /**
     * Whether a position lies inside a polygon or on its edge: inside or on
     * its outer ring, and not strictly inside any of its holes.
     *
     * @param list<list<array{0: float|int, 1: float|int}>> $rings the outer ring, then the holes
     */
    private static function inPolygon(float $longitude, float $latitude, array $rings): bool
    {
        foreach ($rings as $i => $ring) {
            $side = self::sideOfRing($longitude, $latitude, $ring);
            if (0 === $i ? $side < 0 : $side > 0) {
                return false;
            }
        }

        return [] !== $rings;
    }

    /**
     * Where a position lies relative to a closed ring, by counting the edges
     * a ray from it crosses: 1 inside, 0 on an edge, -1 outside.
     *
     * @param list<array{0: float|int, 1: float|int}> $ring
     */
    private static function sideOfRing(float $x, float $y, array $ring): int
    {
        $inside = false;
        $count = \count($ring);
        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            [$xi, $yi] = [(float) $ring[$i][0], (float) $ring[$i][1]];
            [$xj, $yj] = [(float) $ring[$j][0], (float) $ring[$j][1]];

            // On the edge: on the line through it, and within its bounding box.
            $cross = ($xj - $xi) * ($y - $yi) - ($yj - $yi) * ($x - $xi);
            if (abs($cross) <= self::EDGE_TOLERANCE * hypot($xj - $xi, $yj - $yi)
                && $x >= min($xi, $xj) - self::EDGE_TOLERANCE && $x <= max($xi, $xj) + self::EDGE_TOLERANCE
                && $y >= min($yi, $yj) - self::EDGE_TOLERANCE && $y <= max($yi, $yj) + self::EDGE_TOLERANCE) {
                return 0;
            }

            if (($yi > $y) !== ($yj > $y) && $x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi) {
                $inside = !$inside;
            }
        }

        return $inside ? 1 : -1;
    }
}
