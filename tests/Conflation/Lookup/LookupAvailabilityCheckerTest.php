<?php

declare(strict_types=1);

namespace App\Tests\Conflation\Lookup;

use App\Conflation\Lookup\AsLocationLookup;
use App\Conflation\Lookup\LocationLookupInterface;
use App\Conflation\Lookup\LookupAvailability;
use App\Conflation\Lookup\LookupAvailabilityChecker;
use App\Tests\Conflation\Fixtures\NearestAddress;
use PHPUnit\Framework\TestCase;

class LookupAvailabilityCheckerTest extends TestCase
{
    public function testItAsksEachLookupAndTellsWhichFailed(): void
    {
        $down = new #[AsLocationLookup(id: 'parking-zones', attributes: ['parkingZone'])] class implements LocationLookupInterface {
            public function lookup(float $longitude, float $latitude): ?array
            {
                throw new \RuntimeException('The parking zone service refused the token with HTTP 401.');
            }
        };

        $results = new LookupAvailabilityChecker([$down, new NearestAddress()])->checkAll(10.21, 56.15);

        $this->assertSame(['addresses', 'parking-zones'], array_map(static fn (LookupAvailability $result): string => $result->id, $results));

        $this->assertTrue($results[0]->isAvailable());
        $this->assertSame('lookups', $results[0]->transport);
        $this->assertSame('8000', $results[0]->answer['postalCode'] ?? null);

        $this->assertFalse($results[1]->isAvailable());
        $this->assertSame('The parking zone service refused the token with HTTP 401.', $results[1]->error);
    }

    public function testAServiceWithoutAnAnswerForThePointIsAvailable(): void
    {
        // NearestAddress has no address west of longitude 10.
        $results = new LookupAvailabilityChecker([new NearestAddress()])->checkAll(9.5, 56.15);

        $this->assertTrue($results[0]->isAvailable());
        $this->assertNull($results[0]->answer);
    }
}
