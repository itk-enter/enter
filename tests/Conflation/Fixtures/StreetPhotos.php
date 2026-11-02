<?php

declare(strict_types=1);

namespace App\Tests\Conflation\Fixtures;

use App\Conflation\Lookup\AsLocationLookup;
use App\Conflation\Lookup\LocationLookupInterface;

/**
 * A lookup that supplies an attribute that AerialPhotos also supplies.
 */
#[AsLocationLookup(id: 'street-photos', attributes: ['image'], recheckEmptyAfter: 'P1D', refreshAfter: 'P30D')]
final class StreetPhotos implements LocationLookupInterface
{
    public function lookup(float $longitude, float $latitude): ?array
    {
        return null;
    }
}
