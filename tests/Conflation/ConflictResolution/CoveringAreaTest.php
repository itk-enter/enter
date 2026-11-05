<?php

declare(strict_types=1);

namespace App\Tests\Conflation\ConflictResolution;

use App\Conflation\ConflictResolution\CoveringArea;
use App\Conflation\Data\StatedValue;
use App\Conflation\Pipeline\ConflictResolver;
use PHPUnit\Framework\TestCase;

class CoveringAreaTest extends TestCase
{
    /**
     * The polygon of one parking bay, about 2.5 m by 5 m.
     *
     * @return array<string, mixed>
     */
    private static function bay(): array
    {
        return ['type' => 'Polygon', 'coordinates' => [[[10.2100, 56.1500], [10.21004, 56.1500], [10.21004, 56.15005], [10.2100, 56.15005], [10.2100, 56.1500]]]];
    }

    /**
     * @return array<string, mixed>
     */
    private static function point(float $longitude, float $latitude): array
    {
        return ['type' => 'Point', 'coordinates' => [$longitude, $latitude]];
    }

    private static function resolve(StatedValue ...$values): mixed
    {
        return new CoveringArea()->resolve(ConflictResolver::LOCATION, $values);
    }

    public function testAnAreaThatContainsTheOtherPointsWins(): void
    {
        $this->assertSame(self::bay(), self::resolve(
            new StatedValue(self::point(10.21002, 56.15002), 'B-1', 'bays'),
            new StatedValue(self::point(10.21001, 56.15003), 'M-1', 'municipality'),
            new StatedValue(self::bay(), 'S-1', 'sites'),
        ));
    }

    public function testAPointOutsideTheAreaLeavesTheLocationToTheNextApproach(): void
    {
        $this->assertNull(self::resolve(
            new StatedValue(self::point(10.21002, 56.15002), 'B-1', 'bays'),
            new StatedValue(self::point(10.2101, 56.1501), 'M-1', 'municipality'),
            new StatedValue(self::bay(), 'S-1', 'sites'),
        ));
    }

    public function testAPointWithinTheMarginOfTheAreaCounts(): void
    {
        // About 1 m and 4 m east of the bay.
        $near = new StatedValue(self::point(10.210056, 56.150025), 'M-1', 'municipality');
        $far = new StatedValue(self::point(10.210105, 56.150025), 'M-1', 'municipality');
        $site = new StatedValue(self::bay(), 'S-1', 'sites');

        $this->assertSame(self::bay(), new CoveringArea(margin: 3.0)->resolve(ConflictResolver::LOCATION, [$near, $site]));
        $this->assertNull(new CoveringArea(margin: 3.0)->resolve(ConflictResolver::LOCATION, [$far, $site]));
        $this->assertNull(new CoveringArea()->resolve(ConflictResolver::LOCATION, [$near, $site]));
    }

    public function testOnlyThePointsOfTheTrustedDataSetsMustLieInTheArea(): void
    {
        $inside = new StatedValue(self::point(10.21002, 56.15002), 'B-1', 'bays');
        // About 4 m east of the bay.
        $far = new StatedValue(self::point(10.210105, 56.150025), 'M-1', 'municipality');
        $site = new StatedValue(self::bay(), 'S-1', 'sites');

        $this->assertSame(self::bay(), new CoveringArea(sourceIds: ['bays'])->resolve(ConflictResolver::LOCATION, [$inside, $far, $site]));
        $this->assertNull(new CoveringArea()->resolve(ConflictResolver::LOCATION, [$inside, $far, $site]));
    }

    public function testWithoutAPointOfATrustedDataSetAllThePointsMustLieInTheArea(): void
    {
        $far = new StatedValue(self::point(10.210105, 56.150025), 'M-1', 'municipality');
        $site = new StatedValue(self::bay(), 'S-1', 'sites');

        $this->assertNull(new CoveringArea(sourceIds: ['bays'])->resolve(ConflictResolver::LOCATION, [$far, $site]));
    }

    public function testItRejectsANegativeMargin(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CoveringArea(margin: -1.0);
    }

    public function testTwoDifferentAreasLeaveTheLocationToTheNextApproach(): void
    {
        $other = self::bay();
        $other['coordinates'][0][1][0] = 10.21005;

        $this->assertNull(self::resolve(
            new StatedValue(self::bay(), 'B-1', 'bays'),
            new StatedValue($other, 'S-1', 'sites'),
        ));
    }

    public function testOnlyPointsLeaveTheLocationToTheNextApproach(): void
    {
        $this->assertNull(self::resolve(
            new StatedValue(self::point(10.21002, 56.15002), 'B-1', 'bays'),
            new StatedValue(self::point(10.21001, 56.15003), 'M-1', 'municipality'),
        ));
    }

    public function testItSettlesOnlyTheLocation(): void
    {
        $this->assertNull(new CoveringArea()->resolve('description', [
            new StatedValue(self::bay(), 'S-1', 'sites'),
            new StatedValue(self::point(10.21002, 56.15002), 'B-1', 'bays'),
        ]));
    }
}
