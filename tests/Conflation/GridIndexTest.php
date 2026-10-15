<?php

declare(strict_types=1);

namespace App\Tests\Conflation;

use App\Conflation\Distance;
use App\Conflation\GridIndex;
use App\Conflation\Pair;
use App\Conflation\Record;
use PHPUnit\Framework\TestCase;

class GridIndexTest extends TestCase
{
    public function testItFindsAPairWithinTheRadius(): void
    {
        $pairs = new GridIndex([
            self::at('a1', 'a', 0, 0),
            self::at('b1', 'b', 3, 0),
        ], 5.0)->pairs();

        $this->assertCount(1, $pairs);
        $this->assertEqualsWithDelta(3.0, $pairs[0]->distance, 0.01);
    }

    public function testItLeavesOutAPairBeyondTheRadius(): void
    {
        $this->assertSame([], new GridIndex([
            self::at('a1', 'a', 0, 0),
            self::at('b1', 'b', 5.5, 0),
        ], 5.0)->pairs());
    }

    public function testItFindsAPairAcrossACellBoundary(): void
    {
        // Positions are bucketed by cell, so a near pair can straddle two.
        // Placing one record just short of a boundary and the other just past
        // it, in every direction, covers the neighbouring cells.
        foreach ([[1, 0], [-1, 0], [0, 1], [0, -1], [1, 1], [-1, -1], [1, -1], [-1, 1]] as [$x, $y]) {
            $pairs = new GridIndex([
                self::at('a1', 'a', 0, 0),
                self::at('b1', 'b', 2.0 * $x, 2.0 * $y),
            ], 5.0)->pairs();

            $this->assertCount(1, $pairs, \sprintf('Direction (%d, %d)', $x, $y));
        }
    }

    public function testItNeverPairsRecordsOfTheSameDataSet(): void
    {
        // Records of one data set describe different things, however close.
        $this->assertSame([], new GridIndex([
            self::at('a1', 'a', 0, 0),
            self::at('a2', 'a', 1, 0),
        ], 5.0)->pairs());
    }

    public function testItReportsEachPairOnce(): void
    {
        $pairs = new GridIndex([
            self::at('a1', 'a', 0, 0),
            self::at('b1', 'b', 1, 0),
            self::at('c1', 'c', 0, 1),
        ], 5.0)->pairs();

        $ids = array_map(static fn (Pair $pair): string => implode('+', [$pair->a->id, $pair->b->id]), $pairs);
        sort($ids);

        $this->assertSame(['a1+b1', 'a1+c1', 'b1+c1'], $ids);
    }

    public function testItKeepsCellsWideEnoughAtHighLatitudes(): void
    {
        // A degree of longitude is much shorter in the north, so cells sized
        // for the equator would be too narrow there.
        $pairs = new GridIndex([
            self::at('a1', 'a', 0, 0, 70.0),
            self::at('b1', 'b', 4.9, 0, 70.0),
        ], 5.0)->pairs();

        $this->assertCount(1, $pairs);
    }

    public function testItRejectsANonPositiveRadius(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new GridIndex([], 0.0);
    }

    /**
     * A record the given number of metres east and north of a point in
     * Aarhus, or at another latitude.
     */
    public static function at(string $id, string $sourceId, float $east, float $north, float $latitude = 56.15): Record
    {
        return new Record(
            $id,
            $sourceId,
            10.2 + $east / (Distance::METRES_PER_DEGREE * cos(deg2rad($latitude))),
            $latitude + $north / Distance::METRES_PER_DEGREE,
        );
    }
}
