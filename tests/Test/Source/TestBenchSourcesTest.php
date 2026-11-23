<?php

declare(strict_types=1);

namespace App\Tests\Test\Source;

use App\Geo\Wgs84Transformer;
use App\Source\AbstractSource;
use App\Source\MtmGmPark\Bench as MtmGmParkBench;
use App\Source\MtmGmPark\PicnicTable as MtmGmParkPicnicTable;
use App\Source\Osm\Bench as OsmBench;
use App\Source\Osm\PicnicTable as OsmPicnicTable;
use App\Test\Source\MtmGmPark\TestBench as MtmGmParkTestBench;
use App\Test\Source\MtmGmPark\TestPicnicTable as MtmGmParkTestPicnicTable;
use App\Test\Source\Osm\TestBench as OsmTestBench;
use App\Test\Source\Osm\TestPicnicTable as OsmTestPicnicTable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests that each bench test source maps a record as its production source
 * does. Only the attributes that name the source are different. A test
 * definition has no publisher, so the entity of a test source has no data
 * provider.
 */
class TestBenchSourcesTest extends TestCase
{
    /**
     * @param array<string, mixed> $record
     */
    #[DataProvider('pairs')]
    public function testItMapsARecordAsItsProductionSourceDoes(AbstractSource $test, AbstractSource $production, array $record): void
    {
        $transformer = new Wgs84Transformer();
        $expected = $production->createNgsiEntity($record, $transformer)?->toPayload([]);
        $actual = $test->createNgsiEntity($record, $transformer)?->toPayload([]);

        $this->assertNotNull($expected);
        $this->assertNotNull($actual);
        $this->assertSame(self::withoutSource($expected), self::withoutSource($actual));
    }

    /**
     * @return iterable<string, array{AbstractSource, AbstractSource, array<string, mixed>}>
     */
    public static function pairs(): iterable
    {
        $node = static fn (array $tags): array => ['type' => 'node', 'id' => 1, 'lat' => 56.15, 'lon' => 10.21, 'tags' => $tags];
        $feature = static fn (): array => [
            'type' => 'Feature',
            'geometry' => ['type' => 'Point', 'coordinates' => [10.16345754, 56.12855299]],
            'properties' => ['elementid' => 'b1', 'elementname' => 'UD4-00001', 'description' => 'Betonbænke', 'status' => 'active'],
        ];

        yield 'OSM bench' => [new OsmTestBench(), new OsmBench(), $node(['amenity' => 'bench', 'two_sided' => 'yes'])];
        yield 'OSM picnic table' => [new OsmTestPicnicTable(), new OsmPicnicTable(), $node(['leisure' => 'picnic_table'])];
        yield 'MTM GM Park bench' => [new MtmGmParkTestBench(), new MtmGmParkBench(), $feature()];
        yield 'MTM GM Park picnic table' => [new MtmGmParkTestPicnicTable(), new MtmGmParkPicnicTable(), $feature()];
    }

    /**
     * Removes the attributes that name the source of the entity.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private static function withoutSource(array $payload): array
    {
        unset($payload['id'], $payload['source'], $payload['sourceId'], $payload['dataProvider']);

        return $payload;
    }
}
