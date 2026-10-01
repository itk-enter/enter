<?php

declare(strict_types=1);

namespace App\Tests\Geo;

use App\Geo\Wgs84Transformer;
use PHPUnit\Framework\TestCase;

class Wgs84TransformerTest extends TestCase
{
    private const string UTM32 = 'EPSG:25832';

    /**
     * Reference coordinates for a building on the Aarhus harbourfront, known
     * independently to sit at roughly 56.1592 N, 10.2152 E. This is the
     * ground truth that catches a broken or missing CRS definition.
     */
    private const float REFERENCE_EASTING = 575477.5441628407;
    private const float REFERENCE_NORTHING = 6224460.129141892;

    private Wgs84Transformer $transformer;

    protected function setUp(): void
    {
        $this->transformer = new Wgs84Transformer();
    }

    public function testItReprojectsFromUtm32ToWgs84(): void
    {
        [$longitude, $latitude] = $this->transformer->toWgs84(
            self::UTM32,
            self::REFERENCE_EASTING,
            self::REFERENCE_NORTHING
        );

        $this->assertEqualsWithDelta(10.2152, $longitude, 0.001);
        $this->assertEqualsWithDelta(56.1592, $latitude, 0.001);
    }

    public function testItReturnsLongitudeLatitudeOrder(): void
    {
        $point = $this->transformer->point(self::UTM32, 574108.2557507273, 6222343.6199512165);

        $this->assertSame('Point', $point['type']);

        // Longitude first, per GeoJSON. A swapped pair is easy to spot here:
        // latitude cannot be 10.19.
        $this->assertGreaterThan(9.0, $point['coordinates'][0]);
        $this->assertLessThan(11.0, $point['coordinates'][0]);
        $this->assertGreaterThan(55.0, $point['coordinates'][1]);
        $this->assertLessThan(57.0, $point['coordinates'][1]);
    }

    public function testItPassesThroughCoordinatesAlreadyInWgs84(): void
    {
        $this->assertSame(
            [10.199512, 56.149753],
            $this->transformer->toWgs84(Wgs84Transformer::TARGET_SRID, 10.199512, 56.149753)
        );
    }

    public function testItSupportsAnotherRegisteredCrs(): void
    {
        // UTM zone 33N: same northing, easting near the zone's central
        // meridian, so the result must land east of zone 32's output.
        [$longitude] = $this->transformer->toWgs84('EPSG:25833', 500000.0, 6224460.0);

        $this->assertEqualsWithDelta(15.0, $longitude, 0.001);
    }

    public function testItRejectsAnUnregisteredCrs(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unknown CRS "EPSG:31700"/');

        $this->transformer->toWgs84('EPSG:31700', 500000.0, 6224460.0);
    }

    public function testItAcceptsAdditionalDefinitions(): void
    {
        $transformer = new Wgs84Transformer([
            'EPSG:23032' => '+proj=utm +zone=32 +ellps=intl +units=m +no_defs',
        ]);

        [$longitude, $latitude] = $transformer->toWgs84('EPSG:23032', self::REFERENCE_EASTING, self::REFERENCE_NORTHING);

        // Different datum, so not identical to EPSG:25832 — but the same
        // corner of the world, which proves the definition was registered.
        $this->assertEqualsWithDelta(10.2152, $longitude, 0.01);
        $this->assertEqualsWithDelta(56.1592, $latitude, 0.01);
    }

    public function testItReprojectsAPointGeometry(): void
    {
        $geometry = $this->transformer->transformGeometry(self::UTM32, [
            'type' => 'Point',
            'coordinates' => [self::REFERENCE_EASTING, self::REFERENCE_NORTHING],
        ]);

        $this->assertSame('Point', $geometry['type']);
        $this->assertEqualsWithDelta(10.2152, $geometry['coordinates'][0], 0.001);
    }

    public function testItReprojectsALineString(): void
    {
        $geometry = $this->transformer->transformGeometry(self::UTM32, [
            'type' => 'LineString',
            'coordinates' => [
                [574108.2557507273, 6222343.6199512165],
                [self::REFERENCE_EASTING, self::REFERENCE_NORTHING],
            ],
        ]);

        $this->assertSame('LineString', $geometry['type']);
        $this->assertCount(2, $geometry['coordinates']);
        $this->assertEqualsWithDelta(10.1926, $geometry['coordinates'][0][0], 0.001);
        $this->assertEqualsWithDelta(10.2152, $geometry['coordinates'][1][0], 0.001);
    }

    public function testItReprojectsAPolygonPreservingNesting(): void
    {
        $geometry = $this->transformer->transformGeometry(self::UTM32, [
            'type' => 'Polygon',
            'coordinates' => [
                [
                    [574108.0, 6222343.0],
                    [574208.0, 6222343.0],
                    [574208.0, 6222443.0],
                    [574108.0, 6222343.0],
                ],
            ],
        ]);

        $this->assertSame('Polygon', $geometry['type']);
        $this->assertCount(1, $geometry['coordinates']);
        $this->assertCount(4, $geometry['coordinates'][0]);
        $this->assertIsFloat($geometry['coordinates'][0][0][0]);
    }

    public function testItReprojectsAMultiPolygon(): void
    {
        $ring = [
            [574108.0, 6222343.0],
            [574208.0, 6222343.0],
            [574208.0, 6222443.0],
            [574108.0, 6222343.0],
        ];

        $geometry = $this->transformer->transformGeometry(self::UTM32, [
            'type' => 'MultiPolygon',
            'coordinates' => [[$ring], [$ring]],
        ]);

        $this->assertCount(2, $geometry['coordinates']);
        $this->assertCount(4, $geometry['coordinates'][1][0]);
    }

    public function testItRejectsAGeometryWithoutCoordinates(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->transformer->transformGeometry(self::UTM32, ['type' => 'Point']);
    }

    public function testItRejectsAGeometryCollection(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->transformer->transformGeometry(self::UTM32, [
            'type' => 'GeometryCollection',
            'geometries' => [],
        ]);
    }

    public function testItRejectsAPositionWithASingleOrdinate(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->transformer->transformGeometry(self::UTM32, ['type' => 'Point', 'coordinates' => [574108.0]]);
    }
}
