<?php

namespace App\Tests\Unit;

use geoPHP as GeoPhp;
use PHPUnit\Framework\TestCase;

class GeosTest extends TestCase
{
    public function testGeosLoaded(): void
    {
        $this->assertContains('geos', get_loaded_extensions());
    }

    public function testGeosIntersection(): void
    {
        $geojson0 = [
            'type' => 'Polygon',
            'coordinates' => [
                [
                    [0, 0],
                    [0, 2],
                    [2, 2],
                    [2, 0],
                    [0, 0],
                ],
            ],
        ];

        $geojson1 = [
            'type' => 'Polygon',
            'coordinates' => [
                [
                    [1, 1],
                    [1, 3],
                    [3, 3],
                    [3, 1],
                    [1, 1],
                ],
            ],
        ];

        $geometry0 = GeoPhp::load(json_encode($geojson0), 'json');
        $geometry1 = GeoPhp::load(json_encode($geojson1), 'json');
        $intersection = $geometry0->intersection($geometry1);

        $actual = json_decode($intersection->out('json'), true);
        $this->assertIsArray($actual);
        $this->assertCount(2, $actual);
        $this->assertArrayHasKey('type', $actual);
        $this->assertEquals('Polygon', $actual['type']);
        $this->assertArrayHasKey('coordinates', $actual);
        $coordinates = $actual['coordinates'];
        $this->assertIsArray($coordinates);
        $this->assertCount(1, $coordinates);
        $this->assertCount(5, $coordinates[0]);
        $this->assertEquals([
            [2, 2],
            [2, 1],
            [1, 1],
            [1, 2],
            [2, 2],
        ], $coordinates[0]);
    }
}
