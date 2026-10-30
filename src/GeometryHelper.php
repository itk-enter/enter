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
}
