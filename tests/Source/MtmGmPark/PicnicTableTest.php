<?php

declare(strict_types=1);

namespace App\Tests\Source\MtmGmPark;

use App\Geo\Wgs84Transformer;
use App\Source\MtmGmPark\PicnicTable;
use PHPUnit\Framework\TestCase;

/**
 * Covers what differs from the benches. BenchTest covers the shared mapping.
 */
class PicnicTableTest extends TestCase
{
    public function testPeopleCanSitOnBothSidesOfAPicnicTable(): void
    {
        $entity = new PicnicTable()->createNgsiEntity([
            'type' => 'Feature',
            'geometry' => ['type' => 'Point', 'coordinates' => [10.11978583, 56.09309123, -99]],
            'properties' => ['elementid' => '4eecc79a-e5ea-4503-ab6b-0f13a2a12ae1', 'elementname' => 'UD14-00001', 'status' => 'active'],
        ], new Wgs84Transformer());

        $payload = $entity?->toPayload([]);
        $this->assertSame('urn:ngsi-ld:Bench:aarhus-bench-gmpark-4eecc79a-e5ea-4503-ab6b-0f13a2a12ae1', $payload['id'] ?? null);
        $this->assertSame(['type' => 'Property', 'value' => true], $payload['twoSided'] ?? null);
        $this->assertSame('picnicTable', $payload['additionalInformation']['value']['kind'] ?? null);
    }
}
