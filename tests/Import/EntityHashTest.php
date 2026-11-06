<?php

declare(strict_types=1);

namespace App\Tests\Import;

use App\Import\EntityHash;
use PHPUnit\Framework\TestCase;

class EntityHashTest extends TestCase
{
    public function testItIsASha256Hash(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', EntityHash::of([['id' => 'urn:ngsi-ld:Bench:1']]));
    }

    public function testTheOrderOfTheEntitiesHasNoEffect(): void
    {
        $first = ['id' => 'urn:ngsi-ld:Bench:1', 'name' => ['type' => 'Property', 'value' => 'Mølleparken']];
        $second = ['id' => 'urn:ngsi-ld:Bench:2', 'name' => ['type' => 'Property', 'value' => 'Ådalen']];

        $this->assertSame(EntityHash::of([$first, $second]), EntityHash::of([$second, $first]));
    }

    public function testAChangedValueGivesAnotherHash(): void
    {
        $before = ['id' => 'urn:ngsi-ld:Bench:1', 'name' => ['type' => 'Property', 'value' => 'Mølleparken']];
        $after = ['id' => 'urn:ngsi-ld:Bench:1', 'name' => ['type' => 'Property', 'value' => 'Møllestien']];

        $this->assertNotSame(EntityHash::of([$before]), EntityHash::of([$after]));
    }
}
