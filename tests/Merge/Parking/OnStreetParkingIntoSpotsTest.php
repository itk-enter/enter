<?php

declare(strict_types=1);

namespace App\Tests\Merge\Parking;

use App\Conflation\Data\Record;
use App\Merge\Parking\OnStreetParkingIntoSpots;
use PHPUnit\Framework\TestCase;

class OnStreetParkingIntoSpotsTest extends TestCase
{
    private const string SITE_ID = 'urn:ngsi-ld:OnStreetParking:osm-way-107343720';

    /**
     * A street-side site of data set "sites".
     *
     * @param array<string, mixed>      $attributes
     * @param array<string, mixed>|null $geometry
     */
    private static function site(array $attributes, ?array $geometry = null): Record
    {
        return new Record(self::SITE_ID, 'sites', 10.21, 56.15, 'OnStreetParking', $attributes, $geometry);
    }

    public function testASiteBecomesOneSpotForEachOfItsBays(): void
    {
        $spots = new OnStreetParkingIntoSpots()->restate(self::site(['totalSpotNumber' => 3]));

        $this->assertSame([
            'urn:ngsi-ld:ParkingSpot:osm-way-107343720-bay-1',
            'urn:ngsi-ld:ParkingSpot:osm-way-107343720-bay-2',
            'urn:ngsi-ld:ParkingSpot:osm-way-107343720-bay-3',
        ], array_column($spots, 'id'));

        foreach ($spots as $spot) {
            $this->assertSame('ParkingSpot', $spot->model);
            $this->assertSame('sites', $spot->sourceId);
            $this->assertSame(self::SITE_ID, $spot->restatedFrom);
            $this->assertSame([10.21, 56.15], [$spot->longitude, $spot->latitude]);
        }
    }

    public function testASiteWithoutANumberOfBaysBecomesOneSpot(): void
    {
        $this->assertCount(1, new OnStreetParkingIntoSpots()->restate(self::site([])));
        $this->assertCount(1, new OnStreetParkingIntoSpots()->restate(self::site(['totalSpotNumber' => 0])));
    }

    public function testOnlyTheSpotOfASiteOfOneBayKeepsTheSiteGeometry(): void
    {
        $strip = ['type' => 'LineString', 'coordinates' => [[10.2100, 56.15], [10.2101, 56.15]]];

        $this->assertSame($strip, new OnStreetParkingIntoSpots()->restate(self::site(['totalSpotNumber' => 1], $strip))[0]->geometry);
        $this->assertNull(new OnStreetParkingIntoSpots()->restate(self::site(['totalSpotNumber' => 2], $strip))[0]->geometry);
    }

    public function testASpotStatesWhatAParkingSpotStates(): void
    {
        $spots = new OnStreetParkingIntoSpots()->restate(self::site([
            'name' => 'Handicapparkering',
            'description' => 'Ved indgangen',
            'totalSpotNumber' => 2,
            'category' => ['feeCharged', 'forDisabled'],
            'parkingMode' => ['echelonParking'],
            'additionalInformation.fee:conditional' => 'no @ (Su)',
        ]));

        $this->assertSame([
            'additionalInformation.fee' => 'yes',
            'additionalInformation.fee:conditional' => 'no @ (Su)',
            'category' => ['onStreet'],
            'description' => 'Ved indgangen',
            'name' => 'Handicapparkering',
            'status' => 'unknown',
        ], $spots[0]->attributes);
    }

    public function testTheFeeComesFromTheCategoryOnlyWhenTheSiteStatesNoFee(): void
    {
        $restate = static fn (array $attributes): mixed => new OnStreetParkingIntoSpots()->restate(self::site($attributes))[0]->attributes['additionalInformation.fee'] ?? null;

        $this->assertSame('no', $restate(['category' => ['free']]));
        $this->assertSame('no', $restate(['category' => ['feeCharged'], 'additionalInformation.fee' => 'no']));
        $this->assertNull($restate(['category' => ['forDisabled']]));
    }

    public function testASiteWithoutAUrnKeepsItsWholeIdInTheSpotId(): void
    {
        $site = new Record('S-1', 'sites', 10.21, 56.15, 'OnStreetParking');

        $this->assertSame('urn:ngsi-ld:ParkingSpot:S-1-bay-1', new OnStreetParkingIntoSpots()->restate($site)[0]->id);
    }
}
