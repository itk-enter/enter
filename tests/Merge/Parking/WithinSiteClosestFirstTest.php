<?php

declare(strict_types=1);

namespace App\Tests\Merge\Parking;

use App\Conflation\Data\Cluster;
use App\Conflation\Data\Record;
use App\Conflation\Spatial\Distance;
use App\Merge\Parking\WithinSiteClosestFirst;
use PHPUnit\Framework\TestCase;

class WithinSiteClosestFirstTest extends TestCase
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

    /**
     * A mapped bay of data set "bays".
     */
    private static function bay(string $id, float $east, float $north): Record
    {
        [$longitude, $latitude] = self::at($east, $north);

        return new Record($id, 'bays', $longitude, $latitude, 'ParkingSpot');
    }

    /**
     * A site of data set "sites" with the given number of bays, at a point
     * or with a geometry.
     *
     * @param array<string, mixed>|null $geometry
     */
    private static function site(string $id, int $bays, float $east, float $north, ?array $geometry = null): Record
    {
        [$longitude, $latitude] = self::at($east, $north);

        return new Record($id, 'sites', $longitude, $latitude, 'OnStreetParking', ['totalSpotNumber' => $bays], $geometry);
    }

    /**
     * A site S-1 of four bays: a strip 20 m long and 4 m wide, its south-west
     * corner at the origin, at its centre point.
     */
    private static function strip(): Record
    {
        $geometry = ['type' => 'Polygon', 'coordinates' => [[self::at(0, 0), self::at(20, 0), self::at(20, 4), self::at(0, 4), self::at(0, 0)]]];

        return self::site('S-1', 4, 10, 2, $geometry);
    }

    /**
     * The clusters that hold a mapped bay, by the id of that bay, each with
     * the id of the input record of its other member.
     *
     * @param list<Cluster> $clusters
     *
     * @return array<string, list<string>>
     */
    private static function matched(array $clusters): array
    {
        $matched = [];
        foreach ($clusters as $cluster) {
            $mapped = $cluster->record('bays');
            if (null !== $mapped) {
                $others = array_values(array_filter($cluster->records, static fn (Record $record): bool => $record !== $mapped));
                $matched[$mapped->id] = array_map(static fn (Record $record): string => $record->inputId(), $others);
            }
        }
        ksort($matched);

        return $matched;
    }

    /**
     * The restated bays that matched no mapped bay.
     *
     * @param list<Cluster> $clusters
     *
     * @return list<Record>
     */
    private static function unmatched(array $clusters): array
    {
        $unmatched = array_filter($clusters, static fn (Cluster $cluster): bool => null === $cluster->record('bays'));

        return array_merge(...array_map(static fn (Cluster $cluster): array => $cluster->records, array_values($unmatched)));
    }

    public function testMappedBaysAnywhereInsideASiteMatchItsBays(): void
    {
        // The mapped bays are at the east end of the strip, 6–9.5 m from its
        // centre point and out of the radius of it, but inside its area.
        $clusters = new WithinSiteClosestFirst(5.0)->merge([
            self::strip(),
            self::bay('B-1', 16, 2),
            self::bay('B-2', 18, 2),
            self::bay('B-3', 19.5, 2),
        ]);

        $this->assertCount(4, $clusters);
        $this->assertSame(['B-1' => ['S-1'], 'B-2' => ['S-1'], 'B-3' => ['S-1']], self::matched($clusters));
        $this->assertCount(1, self::unmatched($clusters));
    }

    public function testAMappedBayNearTheEdgeOfASiteMatchesIt(): void
    {
        $clusters = new WithinSiteClosestFirst(5.0)->merge([self::strip(), self::bay('B-1', 22, 2)]);

        $this->assertSame(['B-1' => ['S-1']], self::matched($clusters));
    }

    public function testAMappedBayOutOfTheRadiusOfTheEdgeOfASiteStaysApart(): void
    {
        $clusters = new WithinSiteClosestFirst(5.0)->merge([self::strip(), self::bay('B-1', 28, 2)]);

        $this->assertSame(['B-1' => []], self::matched($clusters));
        $this->assertCount(4, self::unmatched($clusters));
    }

    public function testAnUnmatchedBayTakesAFreePlaceInItsSiteAndIsGenerated(): void
    {
        $clusters = new WithinSiteClosestFirst(5.0)->merge([
            self::strip(),
            self::bay('B-1', 16, 2),
            self::bay('B-2', 18, 2),
            self::bay('B-3', 20, 2),
        ]);

        $unmatched = self::unmatched($clusters);
        $this->assertCount(1, $unmatched);
        $this->assertSame('S-1', $unmatched[0]->restatedFrom);
        $this->assertTrue($unmatched[0]->attributes[WithinSiteClosestFirst::GENERATED] ?? null);

        [$longitude, $latitude] = self::at(2.5, 2);
        $this->assertEqualsWithDelta($longitude, $unmatched[0]->longitude, 1e-7);
        $this->assertEqualsWithDelta($latitude, $unmatched[0]->latitude, 1e-7);

        // A restated bay that matched a mapped bay is not generated.
        foreach ($clusters as $cluster) {
            if (null !== $cluster->record('bays')) {
                $this->assertArrayNotHasKey(WithinSiteClosestFirst::GENERATED, $cluster->record('sites')->attributes ?? []);
            }
        }
    }

    public function testAPointSiteMatchesAMappedBayWithinTheRadius(): void
    {
        $clusters = new WithinSiteClosestFirst(5.0)->merge([
            self::site('S-1', 2, 0, 0),
            self::bay('B-1', 3, 0),
        ]);

        $this->assertSame(['B-1' => ['S-1']], self::matched($clusters));

        // The other bay moves to the mapped bay, which lies within the radius of the site.
        $unmatched = self::unmatched($clusters);
        $this->assertCount(1, $unmatched);
        $this->assertSame(self::at(3, 0), [$unmatched[0]->longitude, $unmatched[0]->latitude]);
    }

    public function testTheBaysOfASiteWithoutMappedBaysStayAtTheSiteAndAreGenerated(): void
    {
        $unmatched = self::unmatched(new WithinSiteClosestFirst(5.0)->merge([self::site('S-1', 3, 0, 0)]));

        $this->assertCount(3, $unmatched);
        foreach ($unmatched as $bay) {
            $this->assertSame(self::at(0, 0), [$bay->longitude, $bay->latitude]);
            $this->assertTrue($bay->attributes[WithinSiteClosestFirst::GENERATED] ?? null);
        }
    }

    public function testTheBayOfASiteOfOneBayIsNotGenerated(): void
    {
        $unmatched = self::unmatched(new WithinSiteClosestFirst(5.0)->merge([self::site('S-1', 1, 0, 0)]));

        $this->assertCount(1, $unmatched);
        $this->assertArrayNotHasKey(WithinSiteClosestFirst::GENERATED, $unmatched[0]->attributes);
    }

    public function testAMappedBayInsideTwoSitesMatchesABayOfOnlyOne(): void
    {
        $clusters = new WithinSiteClosestFirst(5.0)->merge([
            self::site('S-1', 1, 0, 0),
            self::site('S-2', 1, 2, 0),
            self::bay('B-1', 1.5, 0),
        ]);

        $this->assertSame(['B-1' => ['S-2']], self::matched($clusters));
        $this->assertSame(['S-1'], array_map(static fn (Record $record): string => $record->inputId(), self::unmatched($clusters)));
    }

    public function testTwoMappedBaysInsideASitePairByTheirDistanceBeforeTheSiteJoins(): void
    {
        // Two bays of data set "polygons" side by side in the strip, each 3 m wide.
        $polygon = static fn (string $id, float $west): Record => Record::fromGeometry($id, 'polygons', [
            'type' => 'Polygon',
            'coordinates' => [[self::at($west, 0), self::at($west + 3, 0), self::at($west + 3, 4), self::at($west, 4), self::at($west, 0)]],
        ], 'ParkingSpot');

        // M-1 lies inside P-2, and M-2 lies inside P-1. Every record is 0 m
        // from the strip, so only the distances between the bays can pair them.
        $clusters = new WithinSiteClosestFirst(5.0)->merge([
            self::strip(),
            $polygon('P-1', 4),
            $polygon('P-2', 7),
            self::bay('M-1', 9, 2),
            self::bay('M-2', 5, 2),
        ]);

        $this->assertSame(['M-1' => ['P-2', 'S-1'], 'M-2' => ['P-1', 'S-1']], self::matched($clusters));
    }

    public function testASiteWithAnAreaTakesAMappedBayBeforeASiteAtAPointInIt(): void
    {
        // S-2 is the same place mapped again, as a point of two bays inside the strip S-1.
        $clusters = new WithinSiteClosestFirst(5.0)->merge([
            self::strip(),
            self::site('S-2', 2, 10, 2),
            self::bay('B-1', 11, 2),
        ]);

        $this->assertSame(['B-1' => ['S-1']], self::matched($clusters));
    }

    public function testPointSitesOfOneAddressAreOneBayEach(): void
    {
        // Two sites 3 m apart that each state the 2 bays of their address.
        $unmatched = self::unmatched(new WithinSiteClosestFirst(5.0)->merge([
            self::site('S-1', 2, 0, 0),
            self::site('S-2', 2, 3, 0),
        ]));

        $this->assertSame(['S-1', 'S-2'], array_map(static fn (Record $record): string => $record->inputId(), $unmatched));
        $this->assertSame([self::at(0, 0), self::at(3, 0)], array_map(static fn (Record $record): array => [$record->longitude, $record->latitude], $unmatched));
        foreach ($unmatched as $bay) {
            $this->assertArrayNotHasKey(WithinSiteClosestFirst::GENERATED, $bay->attributes);
        }
    }
}
