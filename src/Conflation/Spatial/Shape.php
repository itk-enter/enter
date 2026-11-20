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
     * How far a point may lie from an area and still count as inside it, in
     * degrees: about 0.1 mm. A point from one source is almost never exactly
     * on an edge from another source, because the coordinates are rounded.
     */
    private const float EDGE_TOLERANCE = 1e-9;

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
     * or within EDGE_TOLERANCE of it included, in its holes excluded. Any
     * other geometry contains no point.
     *
     * @param array<string, mixed> $geometry
     */
    public static function contains(array $geometry, float $longitude, float $latitude): bool
    {
        if (!\in_array($geometry['type'] ?? null, ['Polygon', 'MultiPolygon'], true)) {
            return false;
        }

        // The distance to an area is 0 inside it and on its edge.
        return self::load($geometry)->distance(new \Point($longitude, $latitude)) <= self::EDGE_TOLERANCE;
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
     * The bounding box of a geometry, in degrees, widened by a margin in
     * metres on each side. Each point within the margin of the geometry lies
     * in the box.
     *
     * @param array<string, mixed> $geometry
     *
     * @return array{float, float, float, float} the smallest longitude and latitude, then the largest
     */
    public static function bounds(array $geometry, float $margin = 0.0): array
    {
        if (0.0 === $margin) {
            $box = self::load($geometry)->getBBox();

            return [(float) $box['minx'], (float) $box['miny'], (float) $box['maxx'], (float) $box['maxy']];
        }

        // Widen the box in metres, and then take the box around its corners in degrees.
        $box = self::load($geometry, self::METRIC_SRID)->getBBox();
        [$west, $south, $east, $north] = [(float) $box['minx'] - $margin, (float) $box['miny'] - $margin, (float) $box['maxx'] + $margin, (float) $box['maxy'] + $margin];
        $corners = array_map(
            static fn (array $corner): array => self::transformer()->toWgs84(self::METRIC_SRID, ...$corner),
            [[$west, $south], [$east, $south], [$east, $north], [$west, $north]],
        );

        return [min(array_column($corners, 0)), min(array_column($corners, 1)), max(array_column($corners, 0)), max(array_column($corners, 1))];
    }

    /**
     * The centroid of a geometry: the centre of mass of an area, a line or
     * the points.
     *
     * @param array<string, mixed> $geometry
     *
     * @return array{float, float} the longitude and latitude
     */
    public static function centroid(array $geometry): array
    {
        $centroid = self::load($geometry)->centroid();

        return [(float) $centroid->getX(), (float) $centroid->getY()];
    }

    /**
     * Positions for a number of things spread evenly in a LineString, a
     * Polygon or a MultiPolygon.
     *
     * Along a line, each position lies at the middle of one of the equal
     * parts of the line. In an area, the positions lie in the same way along
     * the long centre line of the area. If that line is not in one piece in
     * the area, all the positions are the same point inside the area.
     *
     * @param array<string, mixed> $geometry
     *
     * @return list<array{float, float}> the longitude and latitude of each position
     */
    public static function spread(array $geometry, int $count): array
    {
        if ($count < 1) {
            throw new \InvalidArgumentException(\sprintf('The number of positions must be positive, got %d.', $count));
        }

        $type = $geometry['type'] ?? null;
        if (!\in_array($type, ['LineString', 'Polygon', 'MultiPolygon'], true)) {
            throw new \InvalidArgumentException(\sprintf('Cannot spread positions in a geometry of type %s.', \is_string($type) ? $type : get_debug_type($type)));
        }

        $shape = self::load($geometry, self::METRIC_SRID)->geos();
        $line = 'LineString' === $type ? $shape : self::centreLine($shape);

        $positions = [];
        for ($number = 1; $number <= $count; ++$number) {
            $point = null === $line ? $shape->pointOnSurface() : $line->interpolate(($number - 0.5) / $count, true);
            $positions[] = self::transformer()->toWgs84(self::METRIC_SRID, (float) $point->getX(), (float) $point->getY());
        }

        return $positions;
    }

    /**
     * The long centre line of an area, cut to the area, or null if the cut is
     * not one line. The line joins the middles of the short sides of the
     * smallest rectangle around the area.
     *
     * @param mixed $area a GEOSGeometry
     *
     * @return mixed a GEOSGeometry, or null
     */
    private static function centreLine(mixed $area): mixed
    {
        $rectangle = $area->minimumRotatedRectangle();
        if ('Polygon' !== $rectangle->typeName()) {
            return null;
        }

        $ring = $rectangle->exteriorRing();
        [$a, $b, $c, $d] = array_map(static fn (int $n): \Point => new \Point($ring->pointN($n)->getX(), $ring->pointN($n)->getY()), [0, 1, 2, 3]);

        $ends = $a->distance($b) >= $b->distance($c)
            ? [self::middle($d, $a), self::middle($b, $c)]
            : [self::middle($a, $b), self::middle($c, $d)];
        $cut = $area->intersection(new \LineString($ends)->geos());

        return 'LineString' === $cut->typeName() && $cut->length() > 0 ? $cut : null;
    }

    private static function middle(\Point $a, \Point $b): \Point
    {
        return new \Point(($a->getX() + $b->getX()) / 2, ($a->getY() + $b->getY()) / 2);
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
