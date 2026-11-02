<?php

declare(strict_types=1);

namespace App\Tests\Conflation\Fixtures;

use App\Conflation\Lookup\AsLocationLookup;
use App\Conflation\Lookup\LocationLookupInterface;

/**
 * A lookup that gives a photo of each coordinate east of longitude 10.
 */
#[AsLocationLookup(id: 'aerial-photos', attributes: ['image', 'imageDate'], recheckEmptyAfter: 'P7D', refreshAfter: 'P90D')]
final class AerialPhotos implements LocationLookupInterface
{
    public function lookup(float $longitude, float $latitude): ?array
    {
        return $longitude > 10.0 ? ['image' => \sprintf('https://photos.example/%F,%F.jpg', $longitude, $latitude), 'imageDate' => '2023-04-20'] : null;
    }
}
