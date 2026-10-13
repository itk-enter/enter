<?php

declare(strict_types=1);

namespace App\Conflation;

/**
 * Distance on the earth's surface, in metres.
 *
 * geoPHP's LineString::greatCircleLength() gives the same result when given
 * the same radius, but it builds a line and two points per call, which makes
 * it about four times slower here, where it runs once per candidate pair.
 * proj4php transforms coordinates and has no distance function.
 */
final class Distance
{
    /**
     * The mean earth radius (IUGG), in metres: the usual choice for a sphere
     * used anywhere on earth. Any radius between the polar and the equatorial
     * one changes a distance of a few metres by centimetres at most, well
     * within the precision of the positions compared.
     */
    public const float EARTH_RADIUS = 6371008.8;

    /**
     * Metres per degree of latitude on that sphere.
     */
    public const float METRES_PER_DEGREE = self::EARTH_RADIUS * M_PI / 180;

    /**
     * The haversine distance between two records' points. A sphere is within
     * a fraction of a percent of the ellipsoid, far below the precision of
     * the positions being compared.
     */
    public static function between(Record $a, Record $b): float
    {
        $latitudeA = deg2rad($a->latitude);
        $latitudeB = deg2rad($b->latitude);
        $sinLatitude = sin(($latitudeB - $latitudeA) / 2);
        $sinLongitude = sin(deg2rad($b->longitude - $a->longitude) / 2);

        $h = $sinLatitude ** 2 + cos($latitudeA) * cos($latitudeB) * $sinLongitude ** 2;

        return 2 * self::EARTH_RADIUS * asin(min(1.0, sqrt($h)));
    }
}
