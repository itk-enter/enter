<?php

declare(strict_types=1);

namespace App\Tests\Conflation\ConflictResolution;

use App\Conflation\ConflictResolution\MajorityWins;
use App\Conflation\StatedValue;
use PHPUnit\Framework\TestCase;

class MajorityWinsTest extends TestCase
{
    /**
     * The wheelchair attribute of one toilet, one value per data set.
     *
     * @return list<StatedValue>
     */
    private static function wheelchair(string ...$values): array
    {
        return array_map(
            static fn (string $value, int $i): StatedValue => new StatedValue($value, 'record-'.$i, 'data-set-'.$i),
            $values,
            array_keys($values),
        );
    }

    public function testAValueMoreThanHalfStateWins(): void
    {
        $this->assertSame('yes', new MajorityWins()->resolve('wheelchair', self::wheelchair('no', 'yes', 'yes')));
        $this->assertSame('yes', new MajorityWins()->resolve('wheelchair', self::wheelchair('yes', 'limited', 'yes', 'yes')));
    }

    public function testATieIsLeftToTheNextApproach(): void
    {
        $this->assertNull(new MajorityWins()->resolve('wheelchair', self::wheelchair('yes', 'no')));
        $this->assertNull(new MajorityWins()->resolve('wheelchair', self::wheelchair('yes', 'yes', 'no', 'no')));
    }

    public function testTheMostStatedValueWithoutAMajorityIsLeftToTheNextApproach(): void
    {
        $this->assertNull(new MajorityWins()->resolve('wheelchair', self::wheelchair('yes', 'yes', 'no', 'limited')));
        $this->assertNull(new MajorityWins()->resolve('wheelchair', self::wheelchair('yes', 'no', 'limited')));
    }

    public function testValuesAreComparedAsStated(): void
    {
        $this->assertNull(new MajorityWins()->resolve('wheelchair', self::wheelchair('Yes', 'yes', 'no')));
    }

    public function testListsAndGeometriesAreComparedByContent(): void
    {
        $point = ['type' => 'Point', 'coordinates' => [10.21, 56.15]];
        $values = [
            new StatedValue($point, 'record-a', 'data-set-a'),
            new StatedValue(['type' => 'Point', 'coordinates' => [10.21, 56.15]], 'record-b', 'data-set-b'),
            new StatedValue(['type' => 'Point', 'coordinates' => [10.22, 56.15]], 'record-c', 'data-set-c'),
        ];

        $this->assertSame($point, new MajorityWins()->resolve('location', $values));
    }
}
