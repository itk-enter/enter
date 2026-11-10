<?php

declare(strict_types=1);

namespace App\Tests\Import;

use App\Import\PayloadHash;
use PHPUnit\Framework\TestCase;

class PayloadHashTest extends TestCase
{
    public function testItIsASha256Hash(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', PayloadHash::of([['id' => 'urn:ngsi-ld:Bench:1']]));
    }

    public function testTheOrderOfTheEntitiesHasNoEffect(): void
    {
        $first = ['id' => 'urn:ngsi-ld:Bench:1', 'name' => ['type' => 'Property', 'value' => 'Mølleparken']];
        $second = ['id' => 'urn:ngsi-ld:Bench:2', 'name' => ['type' => 'Property', 'value' => 'Ådalen']];

        $this->assertSame(PayloadHash::of([$first, $second]), PayloadHash::of([$second, $first]));
    }

    public function testTheOrderOfTheKeysHasNoEffect(): void
    {
        $before = [
            'id' => 'urn:ngsi-ld:Bench:1',
            'type' => 'Bench',
            'name' => ['type' => 'Property', 'value' => 'Mølleparken'],
            'location' => ['type' => 'GeoProperty', 'value' => ['type' => 'Point', 'coordinates' => [10.2, 56.15]]],
        ];
        $after = [
            'location' => ['value' => ['coordinates' => [10.2, 56.15], 'type' => 'Point'], 'type' => 'GeoProperty'],
            'name' => ['value' => 'Mølleparken', 'type' => 'Property'],
            'type' => 'Bench',
            'id' => 'urn:ngsi-ld:Bench:1',
        ];

        $this->assertSame(PayloadHash::of([$before]), PayloadHash::of([$after]));
    }

    public function testTheOrderInAListHasAnEffect(): void
    {
        $before = ['id' => 'urn:ngsi-ld:Bench:1', 'location' => ['type' => 'GeoProperty', 'value' => ['type' => 'Point', 'coordinates' => [10.2, 56.15]]]];
        $after = ['id' => 'urn:ngsi-ld:Bench:1', 'location' => ['type' => 'GeoProperty', 'value' => ['type' => 'Point', 'coordinates' => [56.15, 10.2]]]];

        $this->assertNotSame(PayloadHash::of([$before]), PayloadHash::of([$after]));
    }

    public function testAChangedValueGivesAnotherHash(): void
    {
        $before = ['id' => 'urn:ngsi-ld:Bench:1', 'name' => ['type' => 'Property', 'value' => 'Mølleparken']];
        $after = ['id' => 'urn:ngsi-ld:Bench:1', 'name' => ['type' => 'Property', 'value' => 'Møllestien']];

        $this->assertNotSame(PayloadHash::of([$before]), PayloadHash::of([$after]));
    }
}
