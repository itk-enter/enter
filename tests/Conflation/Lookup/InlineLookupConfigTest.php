<?php

declare(strict_types=1);

namespace App\Tests\Conflation\Lookup;

use App\Conflation\Lookup\AsLocationLookup;
use PHPUnit\Framework\TestCase;

/**
 * A lookup without a transport, and the periods that a lookup with a
 * transport must give.
 */
class InlineLookupConfigTest extends TestCase
{
    public function testALookupWithoutATransportNeedsNoPeriods(): void
    {
        $lookup = new AsLocationLookup(id: 'orthophotos', attributes: ['image']);

        $this->assertTrue($lookup->isInline());
        $this->assertNull($lookup->transport);
    }

    public function testALookupWithATransportUsesTheQueue(): void
    {
        $this->assertFalse(new AsLocationLookup(id: 'addresses', attributes: ['address'], recheckEmptyAfter: new \DateInterval('P7D'), refreshAfter: new \DateInterval('P90D'), transport: 'lookups')->isInline());
    }

    public function testALookupWithATransportMustGiveThePeriods(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must give recheckEmptyAfter and refreshAfter');

        new AsLocationLookup(id: 'addresses', attributes: ['address'], transport: 'lookups');
    }
}
