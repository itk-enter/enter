<?php

declare(strict_types=1);

namespace App\Tests\Merge\Parking;

use App\Conflation\Data\Record;
use App\Merge\Parking\OnStreetParkingIntoSpots;
use PHPUnit\Framework\TestCase;

/**
 * Tests that the bays of a street-side site keep the address of the site.
 */
class OnStreetParkingIntoSpotsAddressTest extends TestCase
{
    public function testEachSpotKeepsTheAddressOfTheSite(): void
    {
        $address = ['streetAddress' => 'Domkirkeplads/Bispegade 1'];
        $site = new Record('urn:ngsi-ld:OnStreetParking:aarhus-handicap-172', 'sites', 10.21, 56.15, 'OnStreetParking', ['address' => $address, 'totalSpotNumber' => 3]);

        $spots = new OnStreetParkingIntoSpots()->restate($site);

        $this->assertCount(3, $spots);
        foreach ($spots as $spot) {
            $this->assertSame($address, $spot->attributes['address'] ?? null);
        }
    }

    public function testASpotOfASiteWithoutAnAddressHasNoAddress(): void
    {
        $site = new Record('urn:ngsi-ld:OnStreetParking:aarhus-handicap-173', 'sites', 10.21, 56.15, 'OnStreetParking', ['totalSpotNumber' => 1]);

        $this->assertArrayNotHasKey('address', new OnStreetParkingIntoSpots()->restate($site)[0]->attributes);
    }
}
