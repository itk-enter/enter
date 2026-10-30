<?php

declare(strict_types=1);

namespace App\Conflation\Augmentation;

use App\Conflation\Data\MergedEntity;
use App\Conflation\Data\Record;
use App\Conflation\Data\StatedValue;
use App\Conflation\Spatial\Distance;
use App\Conflation\Spatial\Shape;
use App\GeometryHelper;

/**
 * The default augmentation: an augmenting record reaches the merged entities
 * inside its area, or within the radius of its point.
 *
 * A record with a Polygon or MultiPolygon geometry supplies its attributes to
 * every merged entity whose location is inside the area. The area includes
 * its edge and excludes its holes. Any other record supplies its attributes
 * to every merged entity within the radius of its point.
 *
 * For example, the ground-cover area C-5 states the surface "asphalt". It
 * gives "asphalt" to every parking space inside it that does not state a
 * surface of its own. A space on the edge between C-5 and the gravel area C-6
 * gets both values, and the conflict-resolution approaches of the merge
 * settle them.
 *
 * The augmentation compares a merged entity by one point: its own position,
 * or the centroid of its area. That centroid comes from
 * GeometryHelper::getCentroid(). Without the GEOS extension, geoPHP
 * calculates the centroid of a small area with less precision.
 */
class WithinAreaOrRadius extends AbstractAugmentation
{
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
        $geometry = $record->geometry;
        if (!\in_array($geometry['type'] ?? null, ['Polygon', 'MultiPolygon'], true)) {
            return fn (Record $point): bool => Distance::between($record, $point) <= $this->radius;
        }

        /* @var array<string, mixed> $geometry */
        return static fn (Record $point): bool => Shape::contains($geometry, $point->longitude, $point->latitude);
    }

    /**
     * The point that the augmentation compares a merged entity by: its own
     * position, or the centroid of its area.
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
