<?php

declare(strict_types=1);

namespace App\Tests\Source\Osm;

use App\Geo\Wgs84Transformer;
use App\Source\Osm\PicnicTable;
use PHPUnit\Framework\TestCase;

/**
 * Covers what differs from the benches. BenchTest covers the shared mapping.
 */
class PicnicTableTest extends TestCase
{
    /**
     * @param array<string, string> $tags
     *
     * @return array<string, mixed>
     */
    private static function payload(array $tags): array
    {
        $entity = new PicnicTable()->createNgsiEntity(
            ['type' => 'node', 'id' => 1234, 'lat' => 56.15, 'lon' => 10.2, 'tags' => ['leisure' => 'picnic_table', ...$tags]],
            new Wgs84Transformer(),
        );

        return $entity?->toPayload([]) ?? [];
    }

    public function testPeopleCanSitOnBothSidesOfAPicnicTable(): void
    {
        $payload = self::payload([]);

        $this->assertSame('urn:ngsi-ld:Bench:aarhus-bench-osm-node-1234', $payload['id'] ?? null);
        $this->assertSame(['type' => 'Property', 'value' => true], $payload['twoSided'] ?? null);
        $this->assertSame('picnicTable', $payload['additionalInformation']['value']['kind'] ?? null);
    }

    public function testTheTwoSidedTagWinsOverTheDefault(): void
    {
        $this->assertSame(['type' => 'Property', 'value' => false], self::payload(['two_sided' => 'no'])['twoSided'] ?? null);
    }

    public function testTheSelectorTagIsNotAnOtherTag(): void
    {
        $other = self::payload(['wheelchair' => 'yes'])['additionalInformation']['value']['otherTags'] ?? [];

        $this->assertSame(['wheelchair' => 'yes'], $other);
    }
}
