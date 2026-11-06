<?php

declare(strict_types=1);

namespace App\Tests\Merge\Bench;

use App\Conflation\Data\Cluster;
use App\Conflation\Data\Record;
use App\Conflation\Spatial\Distance;
use App\Merge\Bench\SameKindClosestFirst;
use PHPUnit\Framework\TestCase;

class SameKindClosestFirstTest extends TestCase
{
    private const float LONGITUDE = 10.21;
    private const float LATITUDE = 56.15;

    /**
     * A record of the given kind, the given metres east of a fixed origin.
     */
    private static function record(string $id, string $sourceId, ?string $kind, float $east): Record
    {
        $longitude = self::LONGITUDE + $east / (Distance::METRES_PER_DEGREE * cos(deg2rad(self::LATITUDE)));
        $attributes = null === $kind ? [] : [SameKindClosestFirst::KIND => $kind];

        return new Record($id, $sourceId, $longitude, self::LATITUDE, 'Bench', $attributes);
    }

    /**
     * The ids of the records in each cluster.
     *
     * @param list<Record> $records
     *
     * @return list<list<string>>
     */
    private static function merged(array $records): array
    {
        return array_map(
            static fn (Cluster $cluster): array => array_map(static fn (Record $record): string => $record->id, $cluster->records),
            new SameKindClosestFirst(radius: 3.0)->merge($records),
        );
    }

    public function testItJoinsTwoBenchesOfDifferentDataSets(): void
    {
        $this->assertSame([['B-1', 'O-1']], self::merged([
            self::record('O-1', 'osm-bench', 'bench', 0),
            self::record('B-1', 'gmpark-bench', 'bench', 1),
        ]));
    }

    public function testItDoesNotJoinABenchAndAPicnicTable(): void
    {
        $this->assertSame([['O-1'], ['T-1']], self::merged([
            self::record('O-1', 'osm-bench', 'bench', 0),
            self::record('T-1', 'gmpark-picnic-table', 'picnicTable', 1),
        ]));
    }

    public function testABenchTakesTheNearestBenchAndNotANearerPicnicTable(): void
    {
        $this->assertSame([['B-1', 'O-1'], ['T-1']], self::merged([
            self::record('O-1', 'osm-bench', 'bench', 0),
            self::record('T-1', 'gmpark-picnic-table', 'picnicTable', 0.5),
            self::record('B-1', 'gmpark-bench', 'bench', 2),
        ]));
    }

    public function testARecordWithoutAKindJoinsOnlyARecordWithoutAKind(): void
    {
        $this->assertSame([['A-1', 'C-1'], ['B-1']], self::merged([
            self::record('A-1', 'a', null, 0),
            self::record('B-1', 'b', 'bench', 0.5),
            self::record('C-1', 'c', null, 1),
        ]));
    }
}
