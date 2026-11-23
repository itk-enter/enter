<?php

declare(strict_types=1);

namespace App\Tests\Ngsi;

use App\Ngsi\NgsiEntity;
use PHPUnit\Framework\TestCase;

class NgsiEntityTest extends TestCase
{
    public function testAPropertyCanTellWhenItsValueWasObserved(): void
    {
        $entity = new NgsiEntity('urn:ngsi-ld:Bench:1', 'Bench')
            ->setProperty('seats', 3, observedAt: '2026-11-23T12:00:00Z');

        $this->assertSame(['type' => 'Property', 'value' => 3, 'observedAt' => '2026-11-23T12:00:00Z'], $entity->attribute('seats'));
    }
}
