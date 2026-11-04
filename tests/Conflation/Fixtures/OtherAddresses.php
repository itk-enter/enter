<?php

declare(strict_types=1);

namespace App\Tests\Conflation\Fixtures;

use App\Conflation\Lookup\AsLocationLookup;
use App\Conflation\Lookup\LocationLookupInterface;

/**
 * A lookup that supplies an attribute that NearestAddress also supplies.
 */
#[AsLocationLookup(id: 'other-addresses', attributes: ['address'], recheckEmptyAfter: new \DateInterval('P1D'), refreshAfter: new \DateInterval('P30D'), transport: 'lookups')]
final class OtherAddresses implements LocationLookupInterface
{
    public function lookup(float $longitude, float $latitude): ?array
    {
        return null;
    }
}
