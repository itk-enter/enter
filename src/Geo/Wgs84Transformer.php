<?php

declare(strict_types=1);

namespace App\Geo;

use proj4php\Point;
use proj4php\Proj;
use proj4php\Proj4php;

/**
 * Reprojects coordinates from any registered CRS to WGS84.
 *
 * GeoJSON fixes its coordinates as WGS84 and NGSI-LD inherits that, so every
 * geometry passes through here on its way to an entity and nowhere else.
 *
 * The ETRS89 grids are transformed with a null datum shift, so what is
 * published is ETRS89 labelled WGS84. The two coincided in 1989 and separate by
 * some 2.5 cm a year — ordinary practice in web GIS, and the largest error
 * introduced here. Nothing is rounded on the way: the round trip is exact only
 * at full precision.
 */
final class Wgs84Transformer
{
    public const string TARGET_SRID = 'EPSG:4326';

    /**
     * PROJ definitions for coordinate systems this application reads.
     *
     * @var array<string, string>
     */
    private const array DEFINITIONS = [
        // ETRS89 / UTM zone 32N — most of Denmark.
        'EPSG:25832' => '+proj=utm +zone=32 +ellps=GRS80 +towgs84=0,0,0,0,0,0,0 +units=m +no_defs',
        // ETRS89 / UTM zone 33N — Bornholm and eastwards.
        'EPSG:25833' => '+proj=utm +zone=33 +ellps=GRS80 +towgs84=0,0,0,0,0,0,0 +units=m +no_defs',
        // Web Mercator, in case an input arrives as tile coordinates.
        'EPSG:3857' => '+proj=merc +a=6378137 +b=6378137 +lat_ts=0 +lon_0=0 +x_0=0 +y_0=0 +k=1 +units=m +nadgrids=@null +no_defs',
        self::TARGET_SRID => '+proj=longlat +datum=WGS84 +no_defs',
    ];

    private readonly Proj4php $proj4;

    /** @var array<string, string> */
    private readonly array $definitions;

    /** @var array<string, Proj> */
    private array $projections = [];

    /**
     * @param array<string, string> $definitions additional PROJ definitions, keyed by SRID
     */
    public function __construct(array $definitions = [])
    {
        $this->definitions = [...self::DEFINITIONS, ...$definitions];

        $this->proj4 = new Proj4php();
        foreach ($this->definitions as $srid => $definition) {
            $this->proj4->addDef($srid, $definition);
        }
    }

    /**
     * Coordinates are not rounded.
     *
     * @param string $srid source CRS
     *
     * @return array{float, float} GeoJSON coordinate order: [longitude, latitude]
     */
    public function toWgs84(string $srid, float $x, float $y): array
    {
        if (self::TARGET_SRID === $srid) {
            return [$x, $y];
        }

        $transformed = $this->proj4->transform(
            $this->projection(self::TARGET_SRID),
            new Point($x, $y, $this->projection($srid))
        );

        return [(float) $transformed->x, (float) $transformed->y];
    }

    /**
     * @param string $srid source CRS
     *
     * @return array{type: string, coordinates: array{float, float}} GeoJSON Point
     */
    public function point(string $srid, float $x, float $y): array
    {
        return [
            'type' => 'Point',
            'coordinates' => $this->toWgs84($srid, $x, $y),
        ];
    }

    /**
     * Reprojects a whole GeoJSON geometry, whatever its type.
     *
     * Handles Point, LineString, Polygon, MultiPoint, MultiLineString and
     * MultiPolygon — every type an NGSI-LD GeoProperty accepts. GeometryCollection
     * is not supported, because it carries `geometries` rather than `coordinates`.
     *
     * A third ordinate (elevation) is dropped: the inputs are two-dimensional,
     * and vertical datums are a separate concern this class does not model.
     *
     * @param string               $srid     source CRS
     * @param array<string, mixed> $geometry GeoJSON geometry object
     *
     * @return array{type: string, coordinates: mixed}
     */
    public function transformGeometry(string $srid, array $geometry): array
    {
        $type = $geometry['type'] ?? null;

        if (!\is_string($type) || !\array_key_exists('coordinates', $geometry)) {
            throw new \InvalidArgumentException('Not a GeoJSON geometry: "type" and "coordinates" are both required.');
        }

        return [
            'type' => $type,
            'coordinates' => $this->transformCoordinates($srid, $geometry['coordinates']),
        ];
    }

    /**
     * GeoJSON nests coordinates to a depth that depends on the geometry type:
     * a bare position for Point, an array of positions for LineString, an array
     * of those for Polygon, and so on. Recursing until the first element is
     * numeric handles every depth without enumerating the types.
     *
     * @param array<array-key, mixed> $coordinates
     *
     * @return array<int, mixed>
     */
    private function transformCoordinates(string $srid, array $coordinates): array
    {
        if ([] === $coordinates) {
            throw new \InvalidArgumentException('GeoJSON coordinates must be a non-empty array.');
        }

        if (is_numeric($coordinates[0] ?? null)) {
            if (!is_numeric($coordinates[1] ?? null)) {
                throw new \InvalidArgumentException('A GeoJSON position needs at least two ordinates.');
            }

            return $this->toWgs84($srid, (float) $coordinates[0], (float) $coordinates[1]);
        }

        return array_map(
            fn (mixed $nested): array => $this->transformCoordinates($srid, $nested),
            array_values($coordinates)
        );
    }

    private function projection(string $srid): Proj
    {
        if (!isset($this->definitions[$srid])) {
            throw new \InvalidArgumentException(\sprintf('Unknown CRS "%s". Register a PROJ definition for it before use. Known: %s.', $srid, implode(', ', array_keys($this->definitions))));
        }

        return $this->projections[$srid] ??= new Proj($srid, $this->proj4);
    }
}
