<?php

declare(strict_types=1);

namespace App\Conflation\Augmentation;

use App\Conflation\Data\MergedEntity;
use App\Conflation\Data\Record;
use App\Conflation\Data\StatedValue;
use App\Conflation\Spatial\Distance;
use App\GeometryHelper;

/**
 * The default augmentation: an augmenting record reaches the merged entities
 * inside its area, or within the radius of its point.
 *
 * A record given as a Polygon or MultiPolygon supplies its attributes to every
 * merged entity whose location lies inside the area, on its edge included and
 * in its holes excluded. Any other record is reached through its point, and
 * supplies its attributes to every merged entity within the radius of it.
 *
 * For example, a ground-cover area C-5 stating surface "asphalt" gives
 * "asphalt" to every parking space inside it that does not state a surface of
 * its own. A space on the edge between C-5 and a gravel area C-6 gets both
 * values, and the merge's conflict-resolution approaches settle them.
 *
 * A merged entity is compared by a single point: its own position, or the
 * centroid of its area. That centroid comes from GeometryHelper::getCentroid(),
 * which is not yet reliable for small areas
 * (https://github.com/itk-enter/enter/issues/74).
 */
class WithinAreaOrRadius extends AbstractAugmentation
{
    /**
     * How far, in degrees, a point may lie from an area's edge and still count
     * as on it: about 0.1 mm, to absorb rounding in the coordinates.
     */
    private const float EDGE_TOLERANCE = 1e-9;

    public function augment(array $entities, array $records, array $attributesByModel): array
    {
        $points = array_map(self::pointOf(...), $entities);

        $supplied = [];
        foreach ($records as $record) {
            $attributes = array_values(array_filter(
                $attributesByModel[$record->model] ?? [],
                static fn (string $attribute): bool => null !== ($record->attributes[$attribute] ?? null),
            ));
            if ([] === $attributes) {
                continue;
            }

            $reaches = $this->reach($record);
            foreach ($entities as $i => $entity) {
                if (!$reaches($points[$i])) {
                    continue;
                }

                foreach ($attributes as $attribute) {
                    if (!$entity->has($attribute)) {
                        $supplied[$entity->id][$attribute][] = new StatedValue($record->attributes[$attribute], $record->id, $record->sourceId);
                    }
                }
            }
        }

        return $supplied;
    }

    /**
     * Whether the record reaches a point: inside its area, or within the
     * radius of its point.
     *
     * @return \Closure(Record): bool
     */
    protected function reach(Record $record): \Closure
    {
        $polygons = match ($record->geometry['type'] ?? null) {
            'Polygon' => [$record->geometry['coordinates']],
            'MultiPolygon' => $record->geometry['coordinates'],
            default => null,
        };

        if (null === $polygons) {
            return fn (Record $point): bool => Distance::between($record, $point) <= $this->radius;
        }

        /* @var list<list<list<array{0: float|int, 1: float|int}>>> $polygons */
        return static fn (Record $point): bool => array_any(
            $polygons,
            static fn (array $rings): bool => self::inPolygon($point->longitude, $point->latitude, $rings),
        );
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

    /**
     * The point a merged entity is compared by: its own position, or the
     * centroid of its area.
     */
    private static function pointOf(MergedEntity $entity): Record
    {
        $location = $entity->location;
        if ('Point' === $location['type'] && \is_array($location['coordinates'] ?? null)) {
            [$longitude, $latitude] = $location['coordinates'];
        } else {
            [$longitude, $latitude] = new GeometryHelper()->getCentroid($location)->toArray();
        }

        return new Record($entity->id, '', (float) $longitude, (float) $latitude);
    }
}
