<?php

declare(strict_types=1);

namespace App\Conflation\Spatial;

use App\Conflation\Data\Record;
use App\Geo\Wgs84Transformer;

/**
 * Compares a record's point with a GeoJSON geometry in WGS84: whether the
 * point lies inside it, and how far away it lies.
 *
 * GEOS does the calculations through geoPHP. GEOS calculates on a plane, so
 * distance() first projects the geometry to a CRS in metres. Without the GEOS
 * extension, geoPHP uses less precise calculations of its own. Thus the class
 * throws an exception if the extension is not loaded.
 */
final class Shape
{
    /**
     * The CRS that distance() measures in: ETRS89 / UTM zone 32N, in metres.
     * It covers most of Denmark.
     */
    private const string METRIC_SRID = 'EPSG:25832';

    /**
     * The maximum number of geometries that the class keeps loaded. The
     * callers compare the same geometry with many points, so the class loads
     * each geometry into GEOS only once.
     */
    private const int CACHE_SIZE = 1000;

    /** @var array<string, \Geometry> the loaded geometries, by CRS and content */
    private static array $geometries = [];

    private static ?Wgs84Transformer $transformer = null;

    /**
     * Whether a point lies inside a Polygon or a MultiPolygon: on its edge
     * included, in its holes excluded. Any other geometry contains no point.
     *
     * @param array<string, mixed> $geometry
     */
    public static function contains(array $geometry, float $longitude, float $latitude): bool
    {
        if (!\in_array($geometry['type'] ?? null, ['Polygon', 'MultiPolygon'], true)) {
            return false;
        }

        // covers() includes the edge. contains() excludes it.
        return true === self::load($geometry)->covers(new \Point($longitude, $latitude));
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
        [$x, $y] = self::transformer()->fromWgs84(self::METRIC_SRID, $point->longitude, $point->latitude);

        return (float) self::load($geometry, self::METRIC_SRID)->distance(new \Point($x, $y));
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
        $box = self::load($geometry)->getBBox();

        return [(float) $box['minx'], (float) $box['miny'], (float) $box['maxx'], (float) $box['maxy']];
    }

    /**
     * Loads a GeoJSON geometry in WGS84 into geoPHP, projected to the CRS.
     *
     * @param array<string, mixed> $geometry
     */
    private static function load(array $geometry, string $srid = Wgs84Transformer::TARGET_SRID): \Geometry
    {
        if (!\geoPHP::geosInstalled()) {
            throw new \LogicException('Shape needs the GEOS PHP extension.');
        }

        $key = $srid.' '.hash('xxh128', serialize($geometry));
        if (isset(self::$geometries[$key])) {
            return self::$geometries[$key];
        }

        $type = $geometry['type'] ?? null;
        if (Wgs84Transformer::TARGET_SRID !== $srid) {
            $geometry = self::transformer()->geometryFromWgs84($srid, $geometry);
        }

        $message = \sprintf('Cannot use a geometry of type %s.', \is_string($type) ? $type : get_debug_type($type));
        try {
            $loaded = \geoPHP::load(json_encode($geometry, \JSON_THROW_ON_ERROR), 'json');
        } catch (\Exception $exception) {
            // geoPHP throws a plain exception for GeoJSON that it cannot read.
            throw new \InvalidArgumentException($message, previous: $exception);
        }

        if (!$loaded instanceof \Geometry || $loaded->isEmpty()) {
            throw new \InvalidArgumentException($message);
        }

        if (\count(self::$geometries) >= self::CACHE_SIZE) {
            self::$geometries = [];
        }

        return self::$geometries[$key] = $loaded;
    }

    private static function transformer(): Wgs84Transformer
    {
        return self::$transformer ??= new Wgs84Transformer();
    }
}
