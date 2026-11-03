<?php

declare(strict_types=1);

namespace App\Tests\Geo;

use App\GeometryHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests the centroid of small areas in Aarhus. The tests do not need the GEOS
 * extension.
 */
final class GeometryHelperCentroidTest extends TestCase
{
    private const float LONGITUDE = 10.2039;
    private const float LATITUDE = 56.1529;
    private const float METRES_PER_DEGREE = 111_320.0;

    /**
     * The maximum distance in metres from the correct centroid.
     */
    private const float TOLERANCE = 0.01;

    /**
     * @return iterable<string, array{float}>
     */
    public static function sizes(): iterable
    {
        foreach ([0.5, 1.0, 2.0, 5.0, 20.0, 100.0, 1000.0] as $metres) {
            yield $metres.' m' => [$metres];
        }
    }

    #[DataProvider('sizes')]
    public function testSquare(float $metres): void
    {
        $geometry = ['type' => 'Polygon', 'coordinates' => [self::square(0, 0, $metres)]];

        $this->assertNear($metres / 2, $metres / 2, $geometry);
    }

    #[DataProvider('sizes')]
    public function testSquareInClockwiseOrder(float $metres): void
    {
        $geometry = ['type' => 'Polygon', 'coordinates' => [array_reverse(self::square(0, 0, $metres))]];

        $this->assertNear($metres / 2, $metres / 2, $geometry);
    }

    public function testPolygonWithHole(): void
    {
        // A 10 m square with a 5 m hole in its upper right corner.
        $geometry = ['type' => 'Polygon', 'coordinates' => [
            self::square(0, 0, 10),
            array_reverse(self::square(5, 5, 5)),
        ]];

        // Area 100 at (5, 5) minus area 25 at (7.5, 7.5).
        $expected = (100 * 5 - 25 * 7.5) / 75;
        $this->assertNear($expected, $expected, $geometry);
    }

    public function testMultiPolygon(): void
    {
        // A 2 m square and a 4 m square, 10 m apart.
        $geometry = ['type' => 'MultiPolygon', 'coordinates' => [
            [self::square(0, 0, 2)],
            [self::square(10, 0, 4)],
        ]];

        // Area 4 at (1, 1) and area 16 at (12, 2).
        $this->assertNear((4 * 1 + 16 * 12) / 20, (4 * 1 + 16 * 2) / 20, $geometry);
    }

    public function testPoint(): void
    {
        $geometry = ['type' => 'Point', 'coordinates' => self::coordinates(3, 4)];

        $this->assertNear(3, 4, $geometry);
    }

    public function testPolygonWithoutArea(): void
    {
        $point = self::coordinates(3, 4);
        $geometry = ['type' => 'Polygon', 'coordinates' => [[$point, $point, $point, $point]]];

        $this->assertNear(3, 4, $geometry);
    }

    /**
     * @param array<string, mixed> $geometry
     */
    private function assertNear(float $east, float $north, array $geometry): void
    {
        [$longitude, $latitude] = new GeometryHelper()->getCentroid($geometry)->toArray();

        $error = hypot(
            ($longitude - self::coordinates($east, $north)[0]) * self::metresPerDegreeLongitude(),
            ($latitude - self::coordinates($east, $north)[1]) * self::METRES_PER_DEGREE,
        );
        $this->assertLessThan(self::TOLERANCE, $error, \sprintf('The centroid is %.3f m from the correct point.', $error));
    }

    /**
     * A closed ring of a square, counter-clockwise, with its lower left corner
     * the given number of metres east and north of the reference point.
     *
     * @return list<array{float, float}>
     */
    private static function square(float $east, float $north, float $size): array
    {
        return [
            self::coordinates($east, $north),
            self::coordinates($east + $size, $north),
            self::coordinates($east + $size, $north + $size),
            self::coordinates($east, $north + $size),
            self::coordinates($east, $north),
        ];
    }

    /**
     * @return array{float, float}
     */
    private static function coordinates(float $east, float $north): array
    {
        return [
            self::LONGITUDE + $east / self::metresPerDegreeLongitude(),
            self::LATITUDE + $north / self::METRES_PER_DEGREE,
        ];
    }

    private static function metresPerDegreeLongitude(): float
    {
        return self::METRES_PER_DEGREE * cos(deg2rad(self::LATITUDE));
    }
}
