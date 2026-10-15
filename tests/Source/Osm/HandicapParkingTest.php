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
        // Nine elements, of which one has no id and one no coordinates.
        $this->assertCount(7, $this->entities);
    }

    public function testItAddressesEntitiesByOsmTypeAndId(): void
    {
        // OSM ids are only unique per element type, so the type is part of
        // the identifier; the osm marker keeps it clear of other data sets'
        // aarhus-handicap ids.
        $this->assertSame(
            \sprintf('urn:ngsi-ld:%s:aarhus-handicap-osm-node-3580886094', $this->source->definition->model),
            $this->entities[0]['id']
        );
        $this->assertSame(
            \sprintf('urn:ngsi-ld:%s:aarhus-handicap-osm-way-384028175', $this->source->definition->model),
            $this->entities[2]['id']
        );
        $this->assertSame(
            \sprintf('urn:ngsi-ld:%s:aarhus-handicap-osm-relation-17151325', $this->source->definition->model),
            $this->entities[4]['id']
        );
    }

    public function testItTakesTheTypeFromTheSourceModel(): void
    {
        foreach ($this->entities as $entity) {
            $this->assertSame($this->source->definition->model, $entity['type']);
        }
    }

    public function testItMarksEveryEntityAsDisabledParking(): void
    {
        foreach ($this->entities as $entity) {
            $this->assertContains('forDisabled', $entity['category']['value']);
        }
    }

    public function testItRefinesTheCategoryFromTheFeeTag(): void
    {
        // fee=yes and fee=no map onto the model's feeCharged and free
        // categories; a record without the tag states nothing about charging.
        $this->assertSame(['forDisabled', 'feeCharged'], $this->entities[0]['category']['value']);
        $this->assertSame(['forDisabled', 'free'], $this->entities[4]['category']['value']);
        $this->assertSame(['forDisabled'], $this->entities[2]['category']['value']);
    }

    public function testItIgnoresAFeeValueItDoesNotRecognise(): void
    {
        // fee=donation neither confirms a charge nor rules one out.
        $this->assertSame(['forDisabled'], $this->entities[6]['category']['value']);
    }

    public function testItReadsTheReservedBayCountFromCapacityDisabled(): void
    {
        $this->assertSame(4, $this->entities[0]['totalSpotNumber']['value']);

        // Q-Park SHIP has capacity 299; only its three reserved bays count.
        $this->assertSame(3, $this->entities[1]['totalSpotNumber']['value']);
    }

    public function testItCountsASingleBayByItsOwnCapacity(): void
    {
        // A parking_space=disabled record is reserved in its entirety, so its
        // capacity is the reserved count.
        $this->assertSame(1, $this->entities[2]['totalSpotNumber']['value']);
        $this->assertSame(3, $this->entities[3]['totalSpotNumber']['value']);
    }

    public function testItCountsOneBayWhenASingleBayCarriesNoCapacity(): void
    {
        $this->assertSame(1, $this->entities[6]['totalSpotNumber']['value']);
    }

    public function testItOmitsTheBayCountWhenOnlyItsExistenceIsTagged(): void
    {
        // capacity:disabled=yes; the facility's total capacity of 36 counts
        // every bay and must not stand in for the reserved ones.
        $this->assertArrayNotHasKey('totalSpotNumber', $this->entities[5]);
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
            ['type' => 'Property', 'value' => ['surface' => 'paving_stones', 'wheelchair' => 'yes']],
            $this->entities[6]['additionalInformation']
        );
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
     * the untagged capacity default, capacity:disabled=yes, an unrecognised
     * fee value, the tags carried as additional information, and the two
     * guards that discard a record.
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
                'lat' => 56.17,
                'lon' => 10.23,
                'tags' => ['parking_space' => 'disabled'],
            ],
            [
                'type' => 'node',
                'id' => 103,
                'tags' => ['parking_space' => 'disabled'],
            ],
        ];
    }
}
