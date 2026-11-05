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
     * Gets the centroid of a GeoJSON geometry.
     *
     * Without the GEOS extension, geoPHP calculates the centroid with less
     * precision for a small area. The error is 3 m for a 20 m square and
     * 133 m for a 2 m square. geoPHP also ignores the holes of a polygon. For
     * a line or a multipolygon, it gives the centre of the bounding box.
     *
     * @todo Calculate the correct centroid without the GEOS extension.
     *
     * @param Geometry $geometry
     */
    public function getCentroid(array $geometry): Point
    {
        $centroid = \geoPHP::load(json_encode($geometry), 'geojson')->getCentroid();

        return new Point($centroid->getX(), $centroid->getY());
    }
}
