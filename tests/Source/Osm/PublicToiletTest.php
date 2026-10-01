<?php

declare(strict_types=1);

namespace App\Tests\Source\Osm;

use App\Geo\Wgs84Transformer;
use App\Ngsi\NgsiEntity;
use App\Source\Osm\PublicToilet;
use PHPUnit\Framework\TestCase;

/**
 * Covers the mapping only. Reading the feed is the reader's job.
 *
 * The field mapping here is provisional: overpass-api.de was unreachable
 * when this source was written, so these elements are constructed from the
 * Overpass QL's expected "out center tags" shape rather than a captured
 * live response. Which tags a toilet actually carries in this area is
 * therefore unverified.
 */
class PublicToiletTest extends TestCase
{
    private PublicToilet $source;

    /** @var list<array<string, mixed>> */
    private array $entities;

    protected function setUp(): void
    {
        $this->source = new PublicToilet();
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
        // Seven elements, of which one has no id.
        $this->assertCount(6, $this->entities);
    }

    public function testItAddressesEntitiesByOsmTypeAndId(): void
    {
        $this->assertSame(
            \sprintf('urn:ngsi-ld:%s:aarhus-toilet-osm-node-1234567890', $this->source->definition->model()),
            $this->entities[0]['id']
        );
        $this->assertSame(
            \sprintf('urn:ngsi-ld:%s:aarhus-toilet-osm-way-987654321', $this->source->definition->model()),
            $this->entities[1]['id']
        );
        $this->assertSame(
            \sprintf('urn:ngsi-ld:%s:aarhus-toilet-osm-relation-555666777', $this->source->definition->model()),
            $this->entities[2]['id']
        );
    }

    public function testItPublishesANodeAtItsOwnCoordinates(): void
    {
        $geometry = $this->entities[0]['location']['value'];

        $this->assertSame('Point', $geometry['type']);
        $this->assertSame([10.2134, 56.1496], $geometry['coordinates']);
    }

    public function testItPublishesAWayOrRelationAtItsCentre(): void
    {
        $geometry = $this->entities[1]['location']['value'];

        $this->assertSame('Point', $geometry['type']);
        $this->assertSame([10.2101, 56.1512], $geometry['coordinates']);
    }

    public function testItPublishesTheNameWhenOneIsMapped(): void
    {
        $this->assertSame('Offentligt toilet', $this->entities[0]['name']['value']);
        $this->assertArrayNotHasKey('name', $this->entities[1]);
    }

    public function testItPublishesTheDescriptionWhenOneIsMapped(): void
    {
        $this->assertSame('Toilet ved parken', $this->entities[1]['description']['value']);
        $this->assertArrayNotHasKey('description', $this->entities[0]);
    }

    public function testItMapsTheFacilityTagsOntoTheModel(): void
    {
        $entity = $this->entities[0];

        $this->assertSame('no', $entity['wheelchairAccessible']['value']);
        $this->assertFalse($entity['babyChange']['value']);
        $this->assertSame('flush', $entity['disposal']['value']);
        $this->assertSame(['seated'], $entity['toiletPosition']['value']);
        $this->assertSame(['unisex'], $entity['genderCategory']['value']);
        $this->assertFalse($entity['staffed']['value']);
        $this->assertTrue($entity['handwashing']['value']);
    }

    public function testItCarriesTheFacilityTagsTheModelCannotHoldAsAdditionalInformation(): void
    {
        $this->assertSame(
            [
                'indoor' => 'yes',
                'seasonal' => 'summer',
                'paperSupplied' => 'yes',
            ],
            $this->entities[0]['additionalInformation']['value']
        );
    }

    public function testItPrefersTheToiletsRefinementOverTheGeneralTag(): void
    {
        // The general tag describes the place the record sits on, which may be
        // larger than the toilet; the model describes the toilet itself.
        $this->assertSame('yes', $this->entities[1]['wheelchairAccessible']['value']);
    }

    public function testItFallsBackToTheGeneralTagWithoutARefinement(): void
    {
        $this->assertTrue($this->entities[1]['babyChange']['value']);
    }

    public function testItMapsAWheelchairValueThatIsNeitherYesNorNo(): void
    {
        // wheelchair is not a boolean; "limited" is a real answer.
        $this->assertSame('limited', $this->entities[2]['wheelchairAccessible']['value']);
        $this->assertArrayNotHasKey('additionalInformation', $this->entities[2]);
    }

    public function testItMapsTheAccessTag(): void
    {
        $this->assertSame('customers', $this->entities[1]['accessType']['value']);
        $this->assertArrayNotHasKey('additionalInformation', $this->entities[1]);
    }

    public function testItCarriesValuesThatDoNotFitTheModelAsAdditionalInformation(): void
    {
        $this->assertArrayNotHasKey('accessType', $this->entities[4]);
        $this->assertArrayNotHasKey('level', $this->entities[4]);
        $this->assertArrayNotHasKey('chargeAmount', $this->entities[4]);
        $this->assertSame(
            [
                'level' => '0;1',
                'access' => 'permit',
                'charge' => '5 DKR; 1€',
                'operator' => 'Aarhus Kommune',
                // Tags neither mapped nor omitted, under their OSM keys.
                'otherTags' => ['description:en' => 'Toilet by the park', 'hot_water' => 'no'],
            ],
            $this->entities[4]['additionalInformation']['value']
        );
    }

    public function testItMapsTheListValuedTagsAndDropsUnknownValues(): void
    {
        $entity = $this->entities[5];

        $this->assertSame(['seated', 'urinal'], $entity['toiletPosition']['value']);
        $this->assertSame(['electricHandDryer'], $entity['handDrying']['value']);
        $this->assertSame(['card'], $entity['paymentMethod']['value']);
        $this->assertSame(['female', 'male'], $entity['genderCategory']['value']);
    }

    public function testItMapsANumericLevelAndAChargeInOneCurrency(): void
    {
        $entity = $this->entities[5];

        $this->assertSame(1.0, $entity['level']['value']);
        $this->assertSame(5.0, $entity['chargeAmount']['value']);
        $this->assertSame('DKK', $entity['chargeCurrency']['value']);
        $this->assertSame('pitLatrine', $entity['disposal']['value']);
        $this->assertArrayNotHasKey('additionalInformation', $entity);
    }

    public function testItMapsTheFeeTagOntoFreeAccess(): void
    {
        $this->assertTrue($this->entities[0]['isAccessibleForFree']['value']);
        $this->assertFalse($this->entities[1]['isAccessibleForFree']['value']);
    }

    public function testItOmitsFreeAccessWhenNoFeeIsStated(): void
    {
        $this->assertArrayNotHasKey('isAccessibleForFree', $this->entities[2]);
    }

    public function testItPublishesOpeningHoursWhenStated(): void
    {
        // The model holds opening hours as a list of rules.
        $this->assertSame(['Mo-Su 08:00-20:00'], $this->entities[1]['openingHours']['value']);
        $this->assertArrayNotHasKey('openingHours', $this->entities[0]);
    }

    public function testItRecordsTheRequestedUrlAndQueryAsOneSourceUri(): void
    {
        // The model's source is a single URI, so the Overpass query travels
        // inside it rather than beside it.
        $source = $this->entities[0]['source']['value'];

        $this->assertIsString($source);
        $this->assertStringStartsWith('https://overpass-api.de/api/interpreter?data=', $source);
        $this->assertStringContainsString(rawurlencode('nwr["amenity"="toilets"](area.a);'), $source);
    }

    public function testItOmitsAdditionalInformationWhenNeitherTagIsStated(): void
    {
        $this->assertArrayNotHasKey('additionalInformation', $this->entities[3]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function elements(): array
    {
        return [
            [
                'type' => 'node',
                'id' => 1234567890,
                'lat' => 56.1496,
                'lon' => 10.2134,
                // The full tag set of a real record in this area.
                'tags' => [
                    'amenity' => 'toilets',
                    'building' => 'yes',
                    'fee' => 'no',
                    'indoor' => 'yes',
                    'name' => 'Offentligt toilet',
                    'seasonal' => 'summer',
                    'supervised' => 'no',
                    'toilets:changing_table' => 'no',
                    'toilets:disposal' => 'flush',
                    'toilets:handwashing' => 'yes',
                    'toilets:paper_supplied' => 'yes',
                    'toilets:position' => 'seated',
                    'unisex' => 'yes',
                    'wheelchair' => 'no',
                ],
            ],
            [
                'type' => 'way',
                'id' => 987654321,
                'center' => ['lat' => 56.1512, 'lon' => 10.2101],
                'tags' => [
                    'amenity' => 'toilets',
                    'toilets:wheelchair' => 'yes',
                    'wheelchair' => 'no',
                    'changing_table' => 'yes',
                    'description' => 'Toilet ved parken',
                    'fee' => 'yes',
                    'access' => 'customers',
                    'opening_hours' => 'Mo-Su 08:00-20:00',
                ],
            ],
            [
                'type' => 'relation',
                'id' => 555666777,
                'center' => ['lat' => 56.1523, 'lon' => 10.2088],
                'tags' => [
                    'amenity' => 'toilets',
                    'wheelchair' => 'limited',
                ],
            ],
            [
                'type' => 'node',
                'id' => 222333444,
                'lat' => 56.1534,
                'lon' => 10.2075,
                // Tagged as a toilet and nothing more; the query no longer
                // filters on wheelchair access, so such a record is included.
                'tags' => ['amenity' => 'toilets'],
            ],
            [
                'type' => 'node',
                'lat' => 56.16,
                'lon' => 10.22,
                'tags' => ['amenity' => 'toilets', 'wheelchair' => 'yes'],
                // No id — must be skipped.
            ],
            [
                'type' => 'node',
                'id' => 333444555,
                'lat' => 56.1545,
                'lon' => 10.2064,
                // Values of the kinds the model has no attribute for.
                'tags' => [
                    'amenity' => 'toilets',
                    'level' => '0;1',
                    'access' => 'permit',
                    'charge' => '5 DKR; 1€',
                    'operator' => 'Aarhus Kommune',
                    'hot_water' => 'no',
                    'description:en' => 'Toilet by the park',
                    'building:levels' => '1',
                    'check_date:opening_hours' => '2024-05-01',
                    'payment:coins' => 'yes',
                ],
            ],
            [
                'type' => 'node',
                'id' => 444555666,
                'lat' => 56.1556,
                'lon' => 10.2053,
                'tags' => [
                    'amenity' => 'toilets',
                    'level' => '1',
                    'charge' => '5 DKK',
                    'payment:credit_cards' => 'yes',
                    'payment:coins' => 'no',
                    'toilets:disposal' => 'pitlatrine',
                    'toilets:position' => 'urinal;seated;unknown',
                    'toilets:hands_drying' => 'electric_hand_dryer',
                    'male' => 'yes',
                    'female' => 'yes',
                    'unisex' => 'no',
                ],
            ],
        ];
    }
}
