<?php

declare(strict_types=1);

namespace App\Tests\Source\MtmGmPark;

use App\Geo\Wgs84Transformer;
use App\Ngsi\NgsiEntity;
use App\Source\MtmGmPark\Bench;
use PHPUnit\Framework\TestCase;

/**
 * Covers the mapping only. Reading the feed is the reader's job.
 */
class BenchTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $entities;

    protected function setUp(): void
    {
        $source = new Bench();
        $transformer = new Wgs84Transformer();
        $this->entities = array_values(array_map(
            static fn (NgsiEntity $entity): array => $entity->toPayload(['https://example.com/context.jsonld']),
            array_filter(array_map(
                static fn (array $data): ?NgsiEntity => $source->createNgsiEntity($data, $transformer),
                self::features(),
            )),
        ));
    }

    public function testItSkipsRecordsWithoutAnIdAndRecordsThatAreNotActive(): void
    {
        // Four features: one without elementid, and one that is not active.
        $this->assertCount(2, $this->entities);
    }

    public function testItGivesEachBenchAnIdFromTheRegister(): void
    {
        $this->assertSame('urn:ngsi-ld:Bench:aarhus-bench-gmpark-1bf07439-7f4c-4d38-947c-a21016305949', $this->entities[0]['id']);
        $this->assertSame('Bench', $this->entities[0]['type']);
    }

    public function testItKeepsTheLocationInTwoDimensions(): void
    {
        $this->assertSame(['type' => 'Point', 'coordinates' => [10.16345754, 56.12855299]], $this->entities[0]['location']['value']);
    }

    public function testItSplitsTheAddressIntoStreetPostalCodeAndTown(): void
    {
        $this->assertSame(
            ['streetAddress' => 'Nattergalevej 18B', 'postalCode' => '8210', 'addressLocality' => 'Aarhus V'],
            $this->entities[0]['address']['value'],
        );
        $this->assertSame(['streetAddress' => 'Ved søen'], $this->entities[1]['address']['value']);
    }

    public function testItStatesTheDescriptionAndNotTheSidesOfABench(): void
    {
        $this->assertSame('Betonbænke', $this->entities[0]['description']['value']);
        $this->assertArrayNotHasKey('twoSided', $this->entities[0]);
    }

    public function testItKeepsTheRegisterDetailsAsAdditionalInformation(): void
    {
        $this->assertSame([
            'elementName' => 'UD4-00001',
            'registeredAt' => '2017-01-23 14:35:28.170462',
            'updatedAt' => '2021-10-06 10:49:44.82',
        ], $this->entities[0]['additionalInformation']['value']);
    }

    public function testItLeavesOutTheNamesOfEmployees(): void
    {
        $this->assertStringNotContainsString('Medarbejder', json_encode($this->entities, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function features(): array
    {
        $feature = static fn (array $properties, float $longitude = 10.16345754, float $latitude = 56.12855299): array => [
            'type' => 'Feature',
            'geometry' => ['type' => 'Point', 'coordinates' => [$longitude, $latitude, -99]],
            'properties' => [
                'elementid' => '1bf07439-7f4c-4d38-947c-a21016305949',
                'elementname' => 'UD4-00001',
                'description' => '',
                'address' => '',
                'created' => '2017-01-23 14:35:28.170462',
                'updated' => '2021-10-06 10:49:44.82',
                'status' => 'active',
                'createdby' => 'Medarbejder',
                'updatedby' => 'Medarbejder',
                ...$properties,
            ],
        ];

        return [
            $feature(['description' => 'Betonbænke', 'address' => 'Nattergalevej 18B, 8210 Aarhus V']),
            $feature(['elementid' => 'b2', 'elementname' => 'UD4-00002', 'address' => 'Ved søen'], 10.17, 56.13),
            $feature(['elementid' => '']),
            $feature(['elementid' => 'b4', 'status' => 'inactive']),
        ];
    }
}
