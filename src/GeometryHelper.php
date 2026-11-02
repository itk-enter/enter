<?php

namespace App;

use App\GeometryHelper\Crs;
use proj4php\Point;
use proj4php\Proj;
use proj4php\Proj4php;

/**
 * @phpstan-type Geometry array<array-key, mixed>
 */
final class GeometryHelper
{
    /**
     * @return array{
     *     0: float,
     *     1: float,
     * }
     */
    public function transformCoordinates(float $x, float $y, Crs|string $from, string|Crs|null $to = Crs::EPSG_4326): array
    {
        $point = new Point($x, $y);
        $transformed = $this->transformPoint($point, $to, $from);

        return array_slice($transformed->toArray(), 0, 2);
    }

    public function transformPoint(Point $point, Crs|string|null $from = null, Crs|string $to = Crs::EPSG_4326): Point
    {
        if (null === $point->getProjection() && null === $from) {
            throw new \LogicException('The point has no projection. Specify the projection to transform from.');
        }

        $proj4 = new Proj4php();
        $fromProj = $point->getProjection() ?? new Proj(is_string($from) ? $from : $from->value, $proj4);
        $toProj = new Proj(is_string($to) ? $to : $to->value, $proj4);

        // Proj4php::transform changes its argument. Thus, give it a copy.
        return $proj4->transform($fromProj, $toProj, clone $point);
    }

    public function createProjection(string $srsCode): Proj
    {
        $proj4 = new Proj4php();

        return new Proj($srsCode, $proj4);
    }

    /**
     * @param Geometry $geometry
     *
     * @return Geometry
     */
    public function transformGeoJsonGeometry(array $geometry, Crs|string|null $from, Crs|string $to = Crs::EPSG_4326): array
    {
        $arrayIterator = new \RecursiveArrayIterator($geometry);
        $iterator = new \RecursiveIteratorIterator($arrayIterator, \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $value) {
            if (is_array($value) && array_is_list($value) && 2 === count($value) && is_float($value[0]) && is_float($value[1])) {
                $transformed = $this->transformCoordinates($value[0], $value[1], $to, $from);
                // A sub-iterator holds a copy of its array. Thus, write the
                // new value into each parent, from the deepest level up.
                // https://stackoverflow.com/a/40483953
                $currentDepth = $iterator->getDepth();
                for ($subDepth = $currentDepth; $subDepth >= 0; --$subDepth) {
                    /** @var \RecursiveArrayIterator $subIterator */
                    $subIterator = $iterator->getSubIterator($subDepth);
                    $subIterator->offsetSet($subIterator->key(), $subDepth === $currentDepth ? $transformed : $iterator->getSubIterator($subDepth + 1)->getArrayCopy());
                }
            }
        }

        return $arrayIterator->getArrayCopy();
    }

    /**
     * Gets the centroid of a GeoJSON geometry.
     *
     * For a Polygon or a MultiPolygon, the method calculates the area-weighted
     * centroid, without the holes. It calculates relative to the first point
     * of the geometry. Coordinates near 56° and 10° lose precision in the
     * products of the area formula, and a small area then gets a wrong
     * centroid. geoPHP has this problem without the GEOS extension.
     *
     * For any other geometry, geoPHP calculates the centroid. For a line, it
     * gives the centre of the bounding box.
     *
     * @todo Calculate the length-weighted centroid of a line.
     *
     * @param Geometry $geometry
     */
    public function getCentroid(array $geometry): Point
    {
        $polygons = match ($geometry['type'] ?? null) {
            'Polygon' => [$geometry['coordinates']],
            'MultiPolygon' => $geometry['coordinates'],
            default => [],
        };

        /** @var list<list<list<array{0: float|int, 1: float|int}>>> $polygons */
        $centroid = self::polygonsCentroid($polygons);
        if (null !== $centroid) {
            return $centroid;
        }

        $centroid = \geoPHP::load(json_encode($geometry), 'geojson')->getCentroid();

        return new Point($centroid->getX(), $centroid->getY());
    }

    /**
     * The area-weighted centroid of polygons, or null if they have no area.
     * The first ring of a polygon adds its area. Each other ring is a hole and
     * removes its area.
     *
     * @param list<list<list<array{0: float|int, 1: float|int}>>> $polygons
     */
    private static function polygonsCentroid(array $polygons): ?Point
    {
        $origin = $polygons[0][0][0] ?? null;
        if (null === $origin) {
            return null;
        }
        [$x0, $y0] = $origin;

        $area = $sumX = $sumY = 0.0;
        foreach ($polygons as $rings) {
            foreach ($rings as $index => $ring) {
                $ringArea = $ringX = $ringY = 0.0;
                $count = \count($ring);
                for ($i = 0; $i < $count; ++$i) {
                    $ax = $ring[$i][0] - $x0;
                    $ay = $ring[$i][1] - $y0;
                    $bx = $ring[($i + 1) % $count][0] - $x0;
                    $by = $ring[($i + 1) % $count][1] - $y0;
                    $cross = $ax * $by - $bx * $ay;
                    $ringArea += $cross;
                    $ringX += ($ax + $bx) * $cross;
                    $ringY += ($ay + $by) * $cross;
                }
                if (0.0 === $ringArea) {
                    continue;
                }
                // The orientation of a ring sets the sign of its area. Thus
                // the sign comes from the role of the ring: area or hole.
                $sign = (0 === $index ? 1 : -1) * ($ringArea > 0 ? 1 : -1);
                $area += $sign * $ringArea / 2;
                $sumX += $sign * $ringX / 6;
                $sumY += $sign * $ringY / 6;
            }
        }

        if (0.0 === $area) {
            return null;
        }

        return new Point($x0 + $sumX / $area, $y0 + $sumY / $area);
    }
}
