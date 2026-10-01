<?php

declare(strict_types=1);

namespace App\Tests\Source\Osm;

use App\Geo\Wgs84Transformer;
use App\Ngsi\NgsiEntity;
use App\Source\Osm\HandicapParking;
use PHPUnit\Framework\TestCase;

/**
 * Covers the mapping only. Reading the feed is the reader's job and is
 * covered by App\Tests\SourceReader\SourceReaderOverpassTest.
 */
class HandicapParkingTest extends TestCase
{
    private HandicapParking $source;

    /** @var list<array<string, mixed>> */
    private array $entities;

    protected function setUp(): void
    {
        $this->source = new HandicapParking();
        $transformer = new Wgs84Transformer();
        $this->entities = array_values(array_map(
            static fn (NgsiEntity $entity): array => $entity->toPayload(['https://example.com/context.jsonld']),
            array_filter(array_map(
                fn (array $data) => $this->source->createNgsiEntity($data, $transformer),
                $this->elements()
            ))
        ));
    }

    public function testItSkipsRecordsWithoutAnIdentifierOrGeometry(): void
    {
        // Twelve elements, of which one has no id and one no coordinates.
        $this->assertCount(10, $this->entities);
    }

    public function testItDeclaresEveryModelItPublishes(): void
    {
        $this->assertSame(['ParkingSpot', 'OnStreetParking', 'OffStreetParking'], $this->source->definition->models);

        foreach ($this->entities as $entity) {
            $this->assertContains($entity['type'], $this->source->definition->models);
        }
    }

    public function testItTypesAParkingSpaceAsABay(): void
    {
        // parking_space=disabled with capacity 1, and with no capacity at
        // all, which the tag defines as one.
        $this->assertSame('ParkingSpot', $this->entities[2]['type']);
        $this->assertSame('ParkingSpot', $this->entities[6]['type']);
    }

    public function testItTypesAParkingAreaOfOneReservedBayAsABay(): void
    {
        // amenity=parking whose whole capacity is the one reserved bay is
        // the bay, however the mapper chose to draw it.
        $this->assertSame('ParkingSpot', $this->entities[7]['type']);
    }

    public function testItTypesAFacilityOfUnknownSizeAsASite(): void
    {
        // One reserved bay, but no capacity: the record is a facility, not
        // the bay.
        $this->assertSame('OnStreetParking', $this->entities[9]['type']);
        $this->assertSame(1, $this->entities[9]['totalSpotNumber']['value']);
    }

    public function testItTypesARowOfBaysAsASite(): void
    {
        // parking_space=disabled with capacity 3 holds three bays.
        $this->assertSame('OnStreetParking', $this->entities[3]['type']);
        $this->assertSame(3, $this->entities[3]['totalSpotNumber']['value']);
    }

    public function testItSitesAFacilityByItsParkingTag(): void
    {
        $this->assertSame('OffStreetParking', $this->entities[0]['type'], 'surface');
        $this->assertSame('OffStreetParking', $this->entities[1]['type'], 'underground');
        $this->assertSame('OffStreetParking', $this->entities[8]['type'], 'surface');
        $this->assertSame('OnStreetParking', $this->entities[4]['type'], 'street_side');
        $this->assertSame('OnStreetParking', $this->entities[9]['type'], 'lane');
    }

    public function testItKeepsAnUnsitedSiteOnTheStreet(): void
    {
        // Neither a row of bays nor this facility carries a parking tag.
        $this->assertSame('OnStreetParking', $this->entities[3]['type']);
        $this->assertSame('OnStreetParking', $this->entities[5]['type']);
    }

    public function testItAddressesEntitiesByModelOsmTypeAndId(): void
    {
        // OSM ids are only unique per element type, so the type is part of
        // the identifier; the osm marker keeps it clear of other data sets'
        // aarhus-handicap ids.
        $this->assertSame('urn:ngsi-ld:OffStreetParking:aarhus-handicap-osm-node-3580886094', $this->entities[0]['id']);
        $this->assertSame('urn:ngsi-ld:ParkingSpot:aarhus-handicap-osm-way-384028175', $this->entities[2]['id']);
        $this->assertSame('urn:ngsi-ld:OnStreetParking:aarhus-handicap-osm-relation-17151325', $this->entities[4]['id']);
    }

    public function testItPublishesABayWithUnknownStatus(): void
    {
        // The schema requires a status and the feed observes none.
        $this->assertSame('unknown', $this->entities[2]['status']['value']);
        $this->assertArrayNotHasKey('status', $this->entities[0]);
    }

    public function testItSitesABayWhenTheRecordSaysWhereItIs(): void
    {
        $this->assertSame(['onStreet'], $this->entities[7]['category']['value']);
    }

    public function testItLeavesABayUnsitedWhenTheRecordDoesNotSay(): void
    {
        // A parking space carries no parking tag, and a guess would misplace
        // half of them.
        $this->assertArrayNotHasKey('category', $this->entities[2]);
        $this->assertArrayNotHasKey('category', $this->entities[6]);
    }

    public function testItPublishesNoBayCountForABay(): void
    {
        $this->assertArrayNotHasKey('totalSpotNumber', $this->entities[2]);
        $this->assertArrayNotHasKey('totalSpotNumber', $this->entities[7]);
    }

    public function testItMarksEverySiteAsDisabledParking(): void
    {
        foreach ([0, 1, 3, 4, 5, 8, 9] as $site) {
            $this->assertContains('forDisabled', $this->entities[$site]['category']['value']);
        }
    }

    public function testItRefinesASitesCategoryFromTheFeeTag(): void
    {
        // fee=yes and fee=no map onto the model's feeCharged and free
        // categories; a record without the tag states nothing about charging.
        $this->assertSame(['forDisabled', 'feeCharged'], $this->entities[0]['category']['value']);
        $this->assertSame(['forDisabled', 'free'], $this->entities[4]['category']['value']);
        $this->assertSame(['forDisabled'], $this->entities[3]['category']['value']);
    }

    public function testItCarriesABaysFeeAsAdditionalInformation(): void
    {
        // A bay has no charging category, so the tag is carried as stated,
        // unrecognised value and all.
        $this->assertSame('donation', $this->entities[6]['additionalInformation']['value']['fee']);
    }

    public function testItReadsTheReservedBayCountFromCapacityDisabled(): void
    {
        $this->assertSame(4, $this->entities[0]['totalSpotNumber']['value']);

        // Q-Park SHIP has capacity 299; only its three reserved bays count.
        $this->assertSame(3, $this->entities[1]['totalSpotNumber']['value']);
    }

    public function testItOmitsTheBayCountWhenOnlyItsExistenceIsTagged(): void
    {
        // capacity:disabled=yes; the facility's total capacity of 36 counts
        // every bay and must not stand in for the reserved ones.
        $this->assertArrayNotHasKey('totalSpotNumber', $this->entities[5]);
    }

    public function testItMapsOrientationOntoParkingMode(): void
    {
        // The on-street model takes one value, the off-street model a list.
        $this->assertSame('perpendicularParking', $this->entities[4]['parkingMode']['value']);
        $this->assertSame(['parallelParking'], $this->entities[8]['parkingMode']['value']);
        $this->assertArrayNotHasKey('parkingMode', $this->entities[0]);
    }

    public function testItPublishesTheNameWhenOneIsMapped(): void
    {
        $this->assertSame('Q-Park SHIP', $this->entities[1]['name']['value']);
        $this->assertArrayNotHasKey('name', $this->entities[0]);
    }

    public function testItPublishesTheDescriptionWhenOneIsMapped(): void
    {
        $this->assertSame('Ved hovedindgangen', $this->entities[6]['description']['value']);
        $this->assertArrayNotHasKey('description', $this->entities[0]);
    }

    public function testItPublishesANodeAsAPoint(): void
    {
        $location = $this->entities[0]['location'];

        $this->assertSame('GeoProperty', $location['type']);
        $this->assertSame('Point', $location['value']['type']);

        // The feed is already WGS84, so the coordinates pass through
        // unchanged — in GeoJSON order, longitude first.
        $this->assertSame([10.2141175, 56.1540563], $location['value']['coordinates']);
    }

    public function testItPublishesAClosedWayAsAPolygon(): void
    {
        $geometry = $this->entities[2]['location']['value'];

        $this->assertSame('Polygon', $geometry['type']);

        $ring = $geometry['coordinates'][0];
        $this->assertCount(5, $ring);
        $this->assertSame($ring[0], $ring[4]);
        $this->assertSame([10.2101549, 56.1572442], $ring[0]);
    }

    public function testItPublishesAnOpenWayAsALineString(): void
    {
        $geometry = $this->entities[3]['location']['value'];

        $this->assertSame('LineString', $geometry['type']);
        $this->assertSame(
            [[10.1061762, 56.1832145], [10.1061762, 56.1832532]],
            $geometry['coordinates']
        );
    }

    public function testItPublishesARelationAtTheCentreOfItsBounds(): void
    {
        // The feed's output mode gives a relation no member geometry, only a
        // bounding box, so its centre stands in for the location.
        $geometry = $this->entities[4]['location']['value'];

        $this->assertSame('Point', $geometry['type']);
        $this->assertEqualsWithDelta(10.24793075, $geometry['coordinates'][0], 1e-9);
        $this->assertEqualsWithDelta(56.08875175, $geometry['coordinates'][1], 1e-9);
    }

    public function testItRecordsTheAccessUrlAsTheEntitySource(): void
    {
        $this->assertSame($this->source->definition->accessUrlWithQuery(), $this->entities[0]['source']['value']);
    }

    public function testItCarriesTagsTheModelCannotHoldAsAdditionalInformation(): void
    {
        $this->assertSame(
            ['type' => 'Property', 'value' => ['fee' => 'donation', 'surface' => 'paving_stones', 'wheelchair' => 'yes']],
            $this->entities[6]['additionalInformation']
        );
        $this->assertSame(['wheelchair' => 'yes'], $this->entities[7]['additionalInformation']['value']);
    }

    public function testItCarriesOnlyTheTagsARecordActuallyHas(): void
    {
        $this->assertSame(['surface' => 'asphalt'], $this->entities[4]['additionalInformation']['value']);
    }

    public function testItOmitsAdditionalInformationWhenARecordCarriesNoSuchTag(): void
    {
        $this->assertArrayNotHasKey('additionalInformation', $this->entities[0]);
    }

    /**
     * The first five elements are records from the live feed — a facility
     * node, a named facility, a closed bay way, a bay way and a relation —
     * kept verbatim except the second way, whose geometry is cut to two
     * vertices to exercise the open-way path. The rest are constructed for
     * capacity:disabled=yes, a bay with an unrecognised fee and the tags
     * carried as additional information, a bay drawn as a parking area, a
     * surface facility with an orientation, a lane facility of unknown
     * size, and the two guards that discard a record.
     *
     * @return list<array<string, mixed>>
     */
    private function elements(): array
    {
        return [
            [
                'type' => 'node',
                'id' => 3580886094,
                'lat' => 56.1540563,
                'lon' => 10.2141175,
                'tags' => [
                    'access' => 'yes',
                    'amenity' => 'parking',
                    'capacity:disabled' => '4',
                    'fee' => 'yes',
                    'parking' => 'surface',
                ],
            ],
            [
                'type' => 'node',
                'id' => 12368170867,
                'lat' => 56.1676256,
                'lon' => 10.2255202,
                'tags' => [
                    'amenity' => 'parking',
                    'brand' => 'Q-Park',
                    'brand:wikidata' => 'Q1127798',
                    'capacity' => '299',
                    'capacity:disabled' => '3',
                    'fee' => 'yes',
                    'layer' => '-1',
                    'name' => 'Q-Park SHIP',
                    'operator' => 'Q-Park',
                    'operator:type' => 'private',
                    'operator:wikidata' => 'Q1127798',
                    'parking' => 'underground',
                ],
            ],
            [
                'type' => 'way',
                'id' => 384028175,
                'bounds' => ['minlat' => 56.1572328, 'minlon' => 10.2101549, 'maxlat' => 56.1572932, 'maxlon' => 10.2102509],
                'geometry' => [
                    ['lat' => 56.1572442, 'lon' => 10.2101549],
                    ['lat' => 56.1572328, 'lon' => 10.2102254],
                    ['lat' => 56.1572819, 'lon' => 10.2102509],
                    ['lat' => 56.1572932, 'lon' => 10.2101805],
                    ['lat' => 56.1572442, 'lon' => 10.2101549],
                ],
                'tags' => [
                    'amenity' => 'parking_space',
                    'capacity' => '1',
                    'parking_space' => 'disabled',
                ],
            ],
            [
                'type' => 'way',
                'id' => 1180544298,
                'bounds' => ['minlat' => 56.1832145, 'minlon' => 10.1061762, 'maxlat' => 56.1832532, 'maxlon' => 10.1063419],
                'geometry' => [
                    ['lat' => 56.1832145, 'lon' => 10.1061762],
                    ['lat' => 56.1832532, 'lon' => 10.1061762],
                ],
                'tags' => [
                    'amenity' => 'parking_space',
                    'capacity' => '3',
                    'parking_space' => 'disabled',
                ],
            ],
            [
                'type' => 'relation',
                'id' => 17151325,
                'bounds' => ['minlat' => 56.0886708, 'minlon' => 10.2477857, 'maxlat' => 56.0888327, 'maxlon' => 10.2480758],
                'tags' => [
                    'access' => 'yes',
                    'amenity' => 'parking',
                    'capacity' => '11',
                    'capacity:disabled' => '1',
                    'fee' => 'no',
                    'orientation' => 'perpendicular',
                    'parking' => 'street_side',
                    'surface' => 'asphalt',
                    'type' => 'multipolygon',
                ],
            ],
            [
                'type' => 'node',
                'id' => 101,
                'lat' => 56.15,
                'lon' => 10.21,
                'tags' => [
                    'amenity' => 'parking',
                    'capacity' => '36',
                    'capacity:disabled' => 'yes',
                ],
            ],
            [
                'type' => 'node',
                'id' => 102,
                'lat' => 56.16,
                'lon' => 10.22,
                'tags' => [
                    'amenity' => 'parking_space',
                    'parking_space' => 'disabled',
                    'description' => 'Ved hovedindgangen',
                    'fee' => 'donation',
                    'surface' => 'paving_stones',
                    'wheelchair' => 'yes',
                ],
            ],
            [
                'type' => 'node',
                'id' => 103,
                'lat' => 56.17,
                'lon' => 10.23,
                'tags' => [
                    'amenity' => 'parking',
                    'capacity' => '1',
                    'capacity:disabled' => '1',
                    'parking' => 'street_side',
                    'wheelchair' => 'yes',
                ],
            ],
            [
                'type' => 'node',
                'id' => 104,
                'lat' => 56.18,
                'lon' => 10.24,
                'tags' => [
                    'amenity' => 'parking',
                    'capacity' => '2',
                    'capacity:disabled' => '2',
                    'orientation' => 'parallel',
                    'parking' => 'surface',
                ],
            ],
            [
                'type' => 'node',
                'id' => 105,
                'lat' => 56.19,
                'lon' => 10.25,
                'tags' => [
                    'amenity' => 'parking',
                    'capacity:disabled' => '1',
                    'parking' => 'lane',
                ],
            ],
            [
                'type' => 'node',
                'lat' => 56.17,
                'lon' => 10.23,
                'tags' => ['parking_space' => 'disabled'],
            ],
            [
                'type' => 'node',
                'id' => 106,
                'tags' => ['parking_space' => 'disabled'],
            ],
        ];
    }
}
