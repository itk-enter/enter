<?php

declare(strict_types=1);

namespace App\Tests\Conflation;

use App\Conflation\Distance;
use App\Conflation\Record;
use PHPUnit\Framework\TestCase;

class RecordTest extends TestCase
{
    public function testAPointIsComparedByItsOwnPosition(): void
    {
        $record = Record::fromGeometry('A-1', 'data-set-a', ['type' => 'Point', 'coordinates' => [10.21, 56.15]], 'ModelA', ['fee' => 'free']);

        $this->assertSame([10.21, 56.15], [$record->longitude, $record->latitude]);
        $this->assertNull($record->geometry);
        $this->assertSame(['type' => 'Point', 'coordinates' => [10.21, 56.15]], $record->location());
        $this->assertSame('ModelA', $record->model);
        $this->assertSame(['fee' => 'free'], $record->attributes);
    }

    public function testAnAreaIsComparedByItsCentroidAndKeepsItsShape(): void
    {
        // A 200 m square: large enough for the centroid to be precise until
        // https://github.com/itk-enter/enter/issues/74 is fixed.
        $east = 200 / (Distance::METRES_PER_DEGREE * cos(deg2rad(56.15)));
        $north = 200 / Distance::METRES_PER_DEGREE;
        $square = ['type' => 'Polygon', 'coordinates' => [[
            [10.21, 56.15], [10.21 + $east, 56.15], [10.21 + $east, 56.15 + $north], [10.21, 56.15 + $north], [10.21, 56.15],
        ]]];

        $record = Record::fromGeometry('A-1', 'data-set-a', $square);
        $centre = new Record('centre', 'data-set-a', 10.21 + $east / 2, 56.15 + $north / 2);

        $this->assertLessThan(0.1, Distance::between($record, $centre));
        $this->assertSame($square, $record->geometry);
        $this->assertSame($square, $record->location());
    }

    public function testAPointNeedsCoordinates(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Record::fromGeometry('A-1', 'data-set-a', ['type' => 'Point', 'coordinates' => []]);
    }
}
