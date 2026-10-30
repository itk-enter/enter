<?php

declare(strict_types=1);

namespace App\Tests\Conflation\Spatial;

use App\Conflation\Data\Record;
use App\Conflation\Spatial\Distance;
use App\Conflation\Spatial\Shape;
use PHPUnit\Framework\TestCase;

class ShapeTest extends TestCase
{
    private const float LONGITUDE = 10.21;
    private const float LATITUDE = 56.15;

    /**
     * A position the given metres east and north of a fixed origin.
     *
     * @return array{float, float}
     */
    private static function at(float $east, float $north): array
    {
        return [
            self::LONGITUDE + $east / (Distance::METRES_PER_DEGREE * cos(deg2rad(self::LATITUDE))),
            self::LATITUDE + $north / Distance::METRES_PER_DEGREE,
        ];
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

    public function testAPointInsideAnAreaIsAtNoDistance(): void
    {
        $this->assertSame(0.0, Shape::distance(self::point(18, 2), self::strip()));
    }

    public function testAPointOnTheEdgeOfAnAreaIsInsideIt(): void
    {
        [$longitude, $latitude] = self::at(0, 2);

        $this->assertTrue(Shape::contains(self::strip(), $longitude, $latitude));
    }

    public function testAPointOutsideAnAreaIsMeasuredToItsNearestEdge(): void
    {
        $this->assertEqualsWithDelta(3.0, Shape::distance(self::point(10, 7), self::strip()), 0.01);
        $this->assertEqualsWithDelta(5.0, Shape::distance(self::point(23, 8), self::strip()), 0.01);
    }

    public function testAPointInAHoleIsOutsideTheArea(): void
    {
        $withHole = self::strip();
        $withHole['coordinates'][] = [self::at(8, 1), self::at(12, 1), self::at(12, 3), self::at(8, 3), self::at(8, 1)];

        $this->assertEqualsWithDelta(1.0, Shape::distance(self::point(10, 2), $withHole), 0.01);
    }

    public function testAPointIsMeasuredToALineAlongItsLength(): void
    {
        $line = ['type' => 'LineString', 'coordinates' => [self::at(0, 0), self::at(30, 0)]];

        $this->assertEqualsWithDelta(2.0, Shape::distance(self::point(15, 2), $line), 0.01);
        $this->assertFalse(Shape::contains($line, ...self::at(15, 0)));
    }

    public function testAPointIsMeasuredToAPointAlongTheSurface(): void
    {
        $other = ['type' => 'Point', 'coordinates' => self::at(3, 4)];

        $this->assertEqualsWithDelta(5.0, Shape::distance(self::point(0, 0), $other), 0.01);
    }

    public function testAnyAreaOfAMultiPolygonContainsAPoint(): void
    {
        $two = ['type' => 'MultiPolygon', 'coordinates' => [self::strip()['coordinates'], [[self::at(40, 0), self::at(44, 0), self::at(44, 4), self::at(40, 0)]]]];

        $this->assertSame(0.0, Shape::distance(self::point(43, 1), $two));
    }

    public function testTheBoundsOfAnAreaAreItsSmallestAndLargestPositions(): void
    {
        $this->assertSame([...self::at(0, 0), ...self::at(20, 4)], Shape::bounds(self::strip()));
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

    public function testAPointOnADiagonalEdgeOfAnAreaIsInsideIt(): void
    {
        [$a, $b] = [self::at(20, 0), self::at(0, 20)];
        $triangle = ['type' => 'Polygon', 'coordinates' => [[self::at(0, 0), $a, $b, self::at(0, 0)]]];

        $this->assertTrue(Shape::contains($triangle, ($a[0] + $b[0]) / 2, ($a[1] + $b[1]) / 2));
    }

    public function testAPointOnACornerOfAnAreaIsInsideIt(): void
    {
        $this->assertTrue(Shape::contains(self::strip(), ...self::at(20, 4)));
    }

    public function testTheSameAreaGivesTheSameAnswersInEitherOrder(): void
    {
        // An area that no other test uses, so that neither call finds it loaded.
        $area = ['type' => 'Polygon', 'coordinates' => [[self::at(100, 0), self::at(120, 0), self::at(120, 4), self::at(100, 4), self::at(100, 0)]]];

        $this->assertEqualsWithDelta(3.0, Shape::distance(self::point(110, 7), $area), 0.01);
        $this->assertTrue(Shape::contains($area, ...self::at(118, 2)));
        $this->assertEqualsWithDelta(3.0, Shape::distance(self::point(110, 7), $area), 0.01);
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
