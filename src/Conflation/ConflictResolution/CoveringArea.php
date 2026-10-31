<?php

declare(strict_types=1);

namespace App\Conflation\ConflictResolution;

use App\Conflation\Data\Record;
use App\Conflation\Data\StatedValue;
use App\Conflation\Pipeline\ConflictResolver;
use App\Conflation\Spatial\Shape;

/**
 * A conflict-resolution approach for the location: an area that contains the
 * points of all the other members wins.
 *
 * If one member states the thing as an area and the others state points in
 * that area, the members agree on the place, and the area describes it best.
 * A point within the margin of the area also counts, because a source can
 * put its point a little beside the area. If the merge names trusted data
 * sets, and a member of one of them states a point, only the points of the
 * trusted data sets must lie in the area. In any other case, the approach
 * leaves the location to the next approach. It settles no other attribute.
 *
 * For example, a site of one parking bay states the polygon of the bay. A
 * mapped bay states a point 2 m beside that polygon. With a margin of 3 m,
 * the merged bay gets the polygon. If the point lies 4 m beside it, the next
 * approach decides.
 */
class CoveringArea extends AbstractConflictResolution
{
    /**
     * @param float             $margin    how far, in metres, a point may lie outside the area and still count
     * @param list<string>|null $sourceIds the trusted data sets, whose points must lie in the area; null to trust every data set
     */
    public function __construct(
        public readonly float $margin = 0.0,
        public readonly ?array $sourceIds = null,
    ) {
        if ($margin < 0) {
            throw new \InvalidArgumentException(\sprintf('The margin must not be negative, got %F.', $margin));
        }
    }

    public function resolve(string $attribute, array $values): mixed
    {
        if (ConflictResolver::LOCATION !== $attribute) {
            return null;
        }

        $areas = array_values(array_filter($values, static fn (StatedValue $value): bool => \in_array(self::type($value), ['Polygon', 'MultiPolygon'], true)));
        if ([] === $areas) {
            return null;
        }

        // All the areas must be the same area.
        $area = $areas[0];
        if (!array_all($areas, static fn (StatedValue $other): bool => $other->sameValueAs($area))) {
            return null;
        }

        // Each other member must state a point.
        $points = array_values(array_filter($values, static fn (StatedValue $value): bool => !$value->sameValueAs($area)));
        if (!array_all($points, static fn (StatedValue $value): bool => 'Point' === self::type($value))) {
            return null;
        }

        // The points of the trusted data sets must lie inside the area or within its margin. If no trusted data set
        // states a point, all the points must.
        $trusted = array_values(array_filter($points, fn (StatedValue $value): bool => null === $this->sourceIds || \in_array($value->sourceId, $this->sourceIds, true)));
        $checked = [] === $trusted ? $points : $trusted;

        return array_all($checked, fn (StatedValue $value): bool => $this->covers((array) $area->value, (array) $value->value)) ? $area->value : null;
    }

    private static function type(StatedValue $value): mixed
    {
        return \is_array($value->value) ? ($value->value['type'] ?? null) : null;
    }

    /**
     * Whether a point lies inside the area, or within the margin of it.
     *
     * @param array<string, mixed> $area
     * @param array<string, mixed> $point
     */
    private function covers(array $area, array $point): bool
    {
        $coordinates = $point['coordinates'] ?? null;
        if (!\is_array($coordinates) || !is_numeric($coordinates[0] ?? null) || !is_numeric($coordinates[1] ?? null)) {
            return false;
        }

        [$longitude, $latitude] = [(float) $coordinates[0], (float) $coordinates[1]];
        if (Shape::contains($area, $longitude, $latitude)) {
            return true;
        }

        return $this->margin > 0 && Shape::distance(new Record('', '', $longitude, $latitude), $area) <= $this->margin;
    }
}
