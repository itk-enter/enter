<?php

declare(strict_types=1);

namespace App\Tests\Conflation\Spatial;

use App\Conflation\Data\Record;
use App\Conflation\Spatial\Shape;
use App\Geo\Wgs84Transformer;
use PHPUnit\Framework\TestCase;

class ShapeTest extends TestCase
{
    /**
     * The origin of the test positions in ETRS89 / UTM zone 32N, the CRS that
     * Shape measures in. It is on the Aarhus harbourfront.
     */
    private const float EASTING = 575000.0;
    private const float NORTHING = 6224000.0;

    private static ?Wgs84Transformer $transformer = null;

    /**
     * A position in WGS84 the given metres east and north of the origin in
     * UTM zone 32N. Thus the expected distances are exact in the CRS that
     * Shape measures in.
     *
     * @return array{float, float}
     */
    private static function at(float $east, float $north): array
    {
        self::$transformer ??= new Wgs84Transformer();

        return self::$transformer->toWgs84('EPSG:25832', self::EASTING + $east, self::NORTHING + $north);
    }

    private static function point(float $east, float $north): Record
    {
        [$longitude, $latitude] = self::at($east, $north);

        return new Record('P', 'data-set', $longitude, $latitude);
    }

    /**
     * A strip 20 m long and 4 m wide, its south-west corner at the origin.
     *
     * @return array<string, mixed>
     */
    private static function strip(): array
    {
        return ['type' => 'Polygon', 'coordinates' => [[self::at(0, 0), self::at(20, 0), self::at(20, 4), self::at(0, 4), self::at(0, 0)]]];
    }

    /**
     * The strip with a hole 4 m by 2 m in its middle.
     *
     * @return array<string, mixed>
     */
    private static function stripWithHole(): array
    {
        $strip = self::strip();
        $strip['coordinates'][] = [self::at(8, 1), self::at(12, 1), self::at(12, 3), self::at(8, 3), self::at(8, 1)];

        return $strip;
    }

    public function testAnAreaCoversThePointsInsideItAndOnItsEdge(): void
    {
        [$a, $b] = [self::at(20, 0), self::at(0, 20)];
        $triangle = ['type' => 'Polygon', 'coordinates' => [[self::at(0, 0), $a, $b, self::at(0, 0)]]];

        $this->assertTrue(Shape::contains(self::strip(), ...self::at(18, 2)));
        $this->assertTrue(Shape::contains(self::strip(), ...self::at(0, 2)));
        $this->assertTrue(Shape::contains(self::strip(), ...self::at(20, 4)));
        $this->assertTrue(Shape::contains($triangle, ($a[0] + $b[0]) / 2, ($a[1] + $b[1]) / 2));
        $this->assertFalse(Shape::contains(self::strip(), ...self::at(10, 7)));
    }

    public function testAHoleIsNotPartOfTheArea(): void
    {
        $this->assertFalse(Shape::contains(self::stripWithHole(), ...self::at(10, 2)));
        $this->assertTrue(Shape::contains(self::stripWithHole(), ...self::at(10, 1)));
    }

    public function testAnyAreaOfAMultiPolygonCoversAPoint(): void
    {
        $two = ['type' => 'MultiPolygon', 'coordinates' => [self::strip()['coordinates'], [[self::at(40, 0), self::at(44, 0), self::at(44, 4), self::at(40, 0)]]]];

        $this->assertTrue(Shape::contains($two, ...self::at(43, 1)));
    }

    public function testOnlyAnAreaCoversAPoint(): void
    {
        $line = ['type' => 'LineString', 'coordinates' => [self::at(0, 0), self::at(30, 0)]];

        $this->assertFalse(Shape::contains($line, ...self::at(15, 0)));
        $this->assertFalse(Shape::contains(['type' => 'Point', 'coordinates' => self::at(0, 0)], ...self::at(0, 0)));
    }

    public function testAPointInsideAnAreaIsAtNoDistance(): void
    {
        $this->assertSame(0.0, Shape::distance(self::point(18, 2), self::strip()));
    }

    public function testAPointOutsideAnAreaIsMeasuredToItsNearestEdge(): void
    {
        $this->assertEqualsWithDelta(3.0, Shape::distance(self::point(10, 7), self::strip()), 1e-6);
        $this->assertEqualsWithDelta(5.0, Shape::distance(self::point(23, 8), self::strip()), 1e-6);
        $this->assertEqualsWithDelta(1.0, Shape::distance(self::point(10, 2), self::stripWithHole()), 1e-6);
    }

    public function testAPointIsMeasuredToALineAndToAPoint(): void
    {
        $line = ['type' => 'LineString', 'coordinates' => [self::at(0, 0), self::at(30, 0)]];
        $other = ['type' => 'Point', 'coordinates' => self::at(3, 4)];

        $this->assertEqualsWithDelta(2.0, Shape::distance(self::point(15, 2), $line), 1e-6);
        $this->assertEqualsWithDelta(5.0, Shape::distance(self::point(0, 0), $other), 1e-6);
    }

    public function testTheBoundsOfAnAreaAreItsSmallestAndLargestPositions(): void
    {
        $strip = self::strip();
        $longitudes = array_column($strip['coordinates'][0], 0);
        $latitudes = array_column($strip['coordinates'][0], 1);

        $this->assertSame([min($longitudes), min($latitudes), max($longitudes), max($latitudes)], Shape::bounds($strip));
    }

    public function testPositionsSpreadAlongALineLieAtTheMiddleOfEqualParts(): void
    {
        $line = ['type' => 'LineString', 'coordinates' => [self::at(0, 0), self::at(30, 0)]];

        $this->assertEqualsWithDelta([self::at(5, 0), self::at(15, 0), self::at(25, 0)], Shape::spread($line, 3), 1e-9);
    }

    public function testPositionsSpreadInAnAreaLieAlongItsLongCentreLine(): void
    {
        // The centre line of an area has no fixed direction, so compare the positions from west to east.
        $positions = Shape::spread(self::strip(), 4);
        sort($positions);

        $this->assertEqualsWithDelta([self::at(2.5, 2), self::at(7.5, 2), self::at(12.5, 2), self::at(17.5, 2)], $positions, 1e-9);
    }

    public function testPositionsInAnAreaWithoutACentreLineInOnePieceAreOnePointInside(): void
    {
        // A U: the centre line crosses the gap between its two arms.
        $u = ['type' => 'Polygon', 'coordinates' => [[
            self::at(0, 0), self::at(20, 0), self::at(20, 10), self::at(16, 10), self::at(16, 4),
            self::at(4, 4), self::at(4, 10), self::at(0, 10), self::at(0, 0),
        ]]];

        $positions = Shape::spread($u, 3);

        $this->assertCount(3, $positions);
        $this->assertSame($positions[0], $positions[1]);
        $this->assertSame($positions[0], $positions[2]);
        $this->assertTrue(Shape::contains($u, ...$positions[0]));
    }

    public function testTheSameAreaGivesTheSameAnswersInEitherOrder(): void
    {
        // An area that no other test uses, so that neither call finds it loaded.
        $area = ['type' => 'Polygon', 'coordinates' => [[self::at(100, 0), self::at(120, 0), self::at(120, 4), self::at(100, 4), self::at(100, 0)]]];

        $this->assertEqualsWithDelta(3.0, Shape::distance(self::point(110, 7), $area), 1e-6);
        $this->assertTrue(Shape::contains($area, ...self::at(118, 2)));
        $this->assertEqualsWithDelta(3.0, Shape::distance(self::point(110, 7), $area), 1e-6);
    }

    public function testItRejectsAGeometryWithoutBounds(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Shape::bounds(['type' => 'GeometryCollection', 'geometries' => []]);
    }

    public function testItRejectsAGeometryItCannotMeasure(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Shape::distance(self::point(0, 0), ['type' => 'GeometryCollection', 'geometries' => []]);
    }

    public function testItSpreadsPositionsOnlyAlongALineOrInAnArea(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Shape::spread(['type' => 'Point', 'coordinates' => self::at(0, 0)], 2);
    }

    public function testItSpreadsAtLeastOnePosition(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Shape::spread(self::strip(), 0);
    }

    public function testItNeedsTheGeosExtension(): void
    {
        $this->expectException(\LogicException::class);

        \geoPHP::geosInstalled(false);
        try {
            Shape::contains(self::strip(), ...self::at(18, 2));
        } finally {
            \geoPHP::geosInstalled(class_exists('GEOSGeometry'));
        }
    }
}
