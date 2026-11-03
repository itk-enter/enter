<?php

declare(strict_types=1);

namespace App\Tests\Conflation\Lookup;

use App\Conflation\Lookup\AsLocationLookup;
use App\Conflation\Lookup\AvailabilityCheckInterface;
use App\Conflation\Lookup\LocationLookupInterface;
use App\Conflation\Lookup\LookupAvailabilityChecker;
use PHPUnit\Framework\TestCase;

/**
 * LookupAvailabilityChecker also calls checkAvailability() of a lookup that implements
 * AvailabilityCheckInterface.
 */
class AvailabilityCheckTest extends TestCase
{
    public function testAFailedServiceCheckMakesTheServiceFail(): void
    {
        $lookup = new #[AsLocationLookup(id: 'orthophotos', attributes: ['image'])] class implements LocationLookupInterface, AvailabilityCheckInterface {
            public function lookup(float $longitude, float $latitude): array
            {
                return ['image' => 'https://enter.example/orthophoto/574755,6223760.jpg'];
            }

            public function checkAvailability(float $longitude, float $latitude): void
            {
                throw new \RuntimeException('The orthophoto WMS gave HTTP 503.');
            }
        };

        $results = new LookupAvailabilityChecker([$lookup])->checkAll(10.2034, 56.1530);

        $this->assertFalse($results[0]->isAvailable());
        $this->assertSame('The orthophoto WMS gave HTTP 503.', $results[0]->error);
        $this->assertNull($results[0]->transport);
    }
}
