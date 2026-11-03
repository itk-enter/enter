<?php

declare(strict_types=1);

namespace App\Tests\Conflation\Lookup;

use App\Conflation\Lookup\AsLocationLookup;
use App\Conflation\Lookup\LocationLookupInterface;
use App\Conflation\Lookup\LookupProbe;
use App\Conflation\Lookup\ProbeResult;
use App\Tests\Conflation\Fixtures\NearestAddress;
use PHPUnit\Framework\TestCase;

class LookupProbeTest extends TestCase
{
    public function testItAsksEachLookupAndTellsWhichFailed(): void
    {
        $down = new #[AsLocationLookup(id: 'addresses', attributes: ['address'], recheckEmptyAfter: 'P7D', refreshAfter: 'P90D')] class implements LocationLookupInterface {
            public function lookup(float $longitude, float $latitude): ?array
            {
                throw new \RuntimeException('The address service refused the token with HTTP 401.');
            }
        };

        $results = new LookupProbe([new NearestAddress(), $down])->probeAll(10.21, 56.15);

        $this->assertSame(['addresses', 'addresses'], array_map(static fn (ProbeResult $result): string => $result->id, $results));

        $this->assertFalse($results[0]->isAvailable());
        $this->assertSame('The address service refused the token with HTTP 401.', $results[0]->error);

        $this->assertTrue($results[1]->isAvailable());
        $this->assertSame('lookups', $results[1]->transport);
        $this->assertSame('8000', $results[1]->answer['postalCode'] ?? null);
    }

    public function testAServiceWithoutAnAnswerForThePointIsAvailable(): void
    {
        // NearestAddress has no address west of longitude 10.
        $results = new LookupProbe([new NearestAddress()])->probeAll(9.5, 56.15);

        $this->assertTrue($results[0]->isAvailable());
        $this->assertNull($results[0]->answer);
    }
}
