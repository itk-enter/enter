<?php

declare(strict_types=1);

namespace App\Tests\Conflation\Lookup;

use App\Conflation\Lookup\AsLocationLookup;
use App\Conflation\Lookup\LocationLookupInterface;
use App\Conflation\Lookup\LookupProbe;
use App\Conflation\Lookup\ProbeResult;
use App\Tests\Conflation\Fixtures\AerialPhotos;
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

        $results = new LookupProbe([new AerialPhotos(), $down])->probeAll(10.21, 56.15);

        $this->assertSame(['addresses', 'aerial-photos'], array_map(static fn (ProbeResult $result): string => $result->id, $results));

        $this->assertFalse($results[0]->isAvailable());
        $this->assertSame('The address service refused the token with HTTP 401.', $results[0]->error);

        $this->assertTrue($results[1]->isAvailable());
        $this->assertSame('lookups', $results[1]->transport);
        $this->assertSame('2023-04-20', $results[1]->answer['imageDate'] ?? null);
    }

    public function testAServiceWithoutAnAnswerForThePointIsAvailable(): void
    {
        // AerialPhotos has no photo west of longitude 10.
        $results = new LookupProbe([new AerialPhotos()])->probeAll(9.5, 56.15);

        $this->assertTrue($results[0]->isAvailable());
        $this->assertNull($results[0]->answer);
    }
}
