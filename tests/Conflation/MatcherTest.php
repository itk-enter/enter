<?php

declare(strict_types=1);

namespace App\Tests\Conflation;

use App\Conflation\Cluster;
use App\Conflation\Matcher;
use App\Conflation\Record;
use PHPUnit\Framework\TestCase;

class MatcherTest extends TestCase
{
    private Matcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new Matcher();
    }

    public function testItSeparatesTwoNeighbouringThingsEachDescribedByThreeDataSets(): void
    {
        // Two public toilets at Tangkrogen, Aarhus, about 22 m apart, each
        // listed by three data sets (positions as published).
        $records = [
            new Record('findtoilet-862', 'findtoilet', 10.207297, 56.139321),
            new Record('other-18', 'other', 10.20729695672183, 56.13932097413219),
            new Record('osm-node-828209184', 'osm', 10.2073172, 56.1393629),
            new Record('findtoilet-903', 'findtoilet', 10.20729, 56.139517),
            new Record('city-10', 'city', 10.20728969153797, 56.139516799221184),
            new Record('osm-way-344112405', 'osm', 10.2073013, 56.1395174),
        ];

        $expected = [
            ['city-10', 'findtoilet-903', 'osm-way-344112405'],
            ['findtoilet-862', 'osm-node-828209184', 'other-18'],
        ];

        $this->assertSame($expected, self::ids($this->matcher->match($records, 10.0)));

        // A radius wide enough to reach across does not merge them either:
        // each would then hold two records from one data set.
        $this->assertSame($expected, self::ids($this->matcher->match($records, 30.0)));
    }

    public function testItPairsARowOfAdjacentThingsOneToOne(): void
    {
        // Five bays 2.5 m apart, recorded by a second data set with an offset
        // of 0.8 m, closer than the bays are to each other. The radius
        // reaches the neighbouring bays, but each record is claimed once.
        $records = [];
        foreach (range(0, 4) as $bay) {
            $records[] = GridIndexTest::at('a'.$bay, 'a', 2.5 * $bay, 0);
            $records[] = GridIndexTest::at('b'.$bay, 'b', 2.5 * $bay + 0.8, 0.3);
        }

        $this->assertSame(
            [['a0', 'b0'], ['a1', 'b1'], ['a2', 'b2'], ['a3', 'b3'], ['a4', 'b4']],
            self::ids($this->matcher->match($records, 5.0))
        );
    }

    public function testItTakesTheClosestPairFirst(): void
    {
        // b1 is within range of both, and goes to the nearer one.
        $clusters = $this->matcher->match([
            GridIndexTest::at('a1', 'a', 0, 0),
            GridIndexTest::at('a2', 'a', 4, 0),
            GridIndexTest::at('b1', 'b', 3, 0),
        ], 5.0);

        $this->assertSame([['a1'], ['a2', 'b1']], self::ids($clusters));
    }

    public function testItDoesNotLetAChainOfMatchesDriftBeyondTheRadius(): void
    {
        // a1–b1 and b1–c1 are each within 5 m, but a1 and c1 are 8 m apart:
        // c1 cannot join, because every member must be within the radius of
        // every other.
        $clusters = $this->matcher->match([
            GridIndexTest::at('a1', 'a', 0, 0),
            GridIndexTest::at('b1', 'b', 3.9, 0),
            GridIndexTest::at('c1', 'c', 8, 0),
        ], 5.0);

        $this->assertSame([['a1', 'b1'], ['c1']], self::ids($clusters));
    }

    public function testItMergesOneThingAcrossFiveDataSets(): void
    {
        $records = array_map(
            static fn (string $source, int $offset): Record => GridIndexTest::at($source.'1', $source, 0.5 * $offset, 0.3 * $offset),
            ['a', 'b', 'c', 'd', 'e'],
            range(0, 4)
        );

        $clusters = $this->matcher->match($records, 3.0);

        $this->assertSame([['a1', 'b1', 'c1', 'd1', 'e1']], self::ids($clusters));
        $this->assertSame(['a', 'b', 'c', 'd', 'e'], $clusters[0]->sourceIds());
        $this->assertSame('c1', $clusters[0]->record('c')?->id);
        $this->assertNull($clusters[0]->record('f'));
    }

    public function testItKeepsARecordThatMatchesNothingAsAClusterOfOne(): void
    {
        $clusters = $this->matcher->match([
            GridIndexTest::at('a1', 'a', 0, 0),
            GridIndexTest::at('b1', 'b', 1, 0),
            GridIndexTest::at('b2', 'b', 100, 0),
        ], 5.0);

        $this->assertSame([['a1', 'b1'], ['b2']], self::ids($clusters));
    }

    public function testItIsRepeatableWhateverTheInputOrder(): void
    {
        $records = [
            GridIndexTest::at('a1', 'a', 0, 0),
            GridIndexTest::at('b1', 'b', 2, 0),
            GridIndexTest::at('b2', 'b', -2, 0),
            GridIndexTest::at('c1', 'c', 1, 1),
        ];

        $expected = self::ids($this->matcher->match($records, 5.0));

        $this->assertSame($expected, self::ids($this->matcher->match(array_reverse($records), 5.0)));
    }

    public function testItRejectsADuplicateRecordId(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->matcher->match([
            GridIndexTest::at('a1', 'a', 0, 0),
            GridIndexTest::at('a1', 'b', 1, 0),
        ], 5.0);
    }

    /**
     * @param list<Cluster> $clusters
     *
     * @return list<list<string>>
     */
    private static function ids(array $clusters): array
    {
        return array_map(
            static function (Cluster $cluster): array {
                $ids = array_map(static fn (Record $record): string => $record->id, $cluster->records);
                sort($ids);

                return $ids;
            },
            $clusters
        );
    }
}
