<?php

declare(strict_types=1);

namespace App\Tests\Conflation\Lookup;

use App\Conflation\Lookup\Location;
use App\GeometryHelper;
use PHPUnit\Framework\TestCase;

class LocationTest extends TestCase
{
    public function testTwoGeometriesAreTheSameToNineDecimalPlaces(): void
    {
        $point = ['type' => 'Point', 'coordinates' => [10.21, 56.15]];

        $this->assertTrue(Location::same($point, ['type' => 'Point', 'coordinates' => [10.2100000000001, 56.15]]));
        $this->assertFalse(Location::same($point, ['type' => 'Point', 'coordinates' => [10.21001, 56.15]]));
        $this->assertFalse(Location::same($point, null));
    }

    public function testALookupGetsThePointItself(): void
    {
        $this->assertSame([10.21, 56.15], new Location(new GeometryHelper())->point(['type' => 'Point', 'coordinates' => [10.21, 56.15]]));
    }

    public function testALookupGetsTheCentroidOfAnArea(): void
    {
        [$longitude, $latitude] = new Location(new GeometryHelper())->point([
            'type' => 'Polygon',
            'coordinates' => [[[10.0, 56.0], [10.2, 56.0], [10.2, 56.2], [10.0, 56.2], [10.0, 56.0]]],
        ]);

        $this->assertEqualsWithDelta(10.1, $longitude, 0.0001);
        $this->assertEqualsWithDelta(56.1, $latitude, 0.0001);
    }
}
