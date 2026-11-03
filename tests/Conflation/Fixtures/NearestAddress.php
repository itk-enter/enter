<?php

declare(strict_types=1);

namespace App\Tests\Conflation\Fixtures;

use App\Conflation\Lookup\AsLocationLookup;
use App\Conflation\Lookup\LocationLookupInterface;

/**
 * A lookup that gives an address for each coordinate east of longitude 10.
 */
#[AsLocationLookup(id: 'addresses', attributes: ['address', 'postalCode'], recheckEmptyAfter: 'P7D', refreshAfter: 'P90D', transport: 'lookups')]
final class NearestAddress implements LocationLookupInterface
{
    public function lookup(float $longitude, float $latitude): ?array
    {
        return $longitude > 10.0 ? ['address' => \sprintf('Nearest address to %F,%F', $longitude, $latitude), 'postalCode' => '8000'] : null;
    }
}
