<?php

declare(strict_types=1);

namespace App\Tests\Conflation\ConflictResolution;

use App\Conflation\ConflictResolution\SourceAuthority;
use App\Conflation\StatedValue;
use PHPUnit\Framework\TestCase;

class SourceAuthorityTest extends TestCase
{
    /**
     * Opening hours of one toilet as three data sets state them.
     *
     * @return list<StatedValue>
     */
    private static function openingHours(): array
    {
        return [
            new StatedValue('08–20', 'A-17', 'data-set-a'),
            new StatedValue('06–22', 'B-903', 'data-set-b'),
            new StatedValue('07–21', 'C-4', 'data-set-c'),
        ];
    }

    public function testTheEarliestListedDataSetDecides(): void
    {
        $this->assertSame('08–20', new SourceAuthority(['data-set-a', 'data-set-c'])->resolve('openingHours', self::openingHours()));
        $this->assertSame('07–21', new SourceAuthority(['data-set-c', 'data-set-a'])->resolve('openingHours', self::openingHours()));
    }

    public function testADataSetThatStatesNothingIsPassedOver(): void
    {
        $this->assertSame('06–22', new SourceAuthority(['data-set-x', 'data-set-b'])->resolve('openingHours', self::openingHours()));
    }

    public function testDataSetsThatAreNotListedSettleNothing(): void
    {
        $this->assertNull(new SourceAuthority(['data-set-x'])->resolve('openingHours', self::openingHours()));
    }

    public function testItNeedsADataSet(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SourceAuthority([]);
    }

    public function testItRejectsADataSetListedTwice(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SourceAuthority(['data-set-a', 'data-set-b', 'data-set-a']);
    }
}
