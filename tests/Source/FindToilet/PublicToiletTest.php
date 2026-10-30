<?php

declare(strict_types=1);

namespace App\Tests\Source\FindToilet;

use App\Geo\Wgs84Transformer;
use App\Ngsi\NgsiEntity;
use App\Source\FindToilet\PublicToilet;
use PHPUnit\Framework\TestCase;

/**
 * Covers the mapping only. Reading the feed is the reader's job.
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
                $this->toilets()
            ))
        ));
    }

    public function testItSkipsRecordsWithoutAnIdOrCoordinates(): void
    {
        // Four records, one without a location.
        $this->assertCount(3, $this->entities);
    }

    public function testItAddressesEntitiesById(): void
    {
        $this->assertSame(
            \sprintf('urn:ngsi-ld:%s:aarhus-toilet-findtoilet-862', $this->source->definition->model),
            $this->entities[0]['id']
        );
    }

    public function testItPublishesTheTitleAsNameAndTheLocationAsAddress(): void
    {
        $this->assertSame('Strandvejen 19', $this->entities[0]['name']['value']);
        $this->assertSame(
            [
                'streetAddress' => 'Strandvejen 19',
                'postalCode' => '8000',
                'addressLocality' => 'Aarhus',
                'addressCountry' => 'DK',
            ],
            $this->entities[0]['address']['value']
        );
    }

    public function testItMapsTheCategoryOntoTheModel(): void
    {
        $this->assertSame('yes', $this->entities[0]['wheelchairAccessible']['value']);
        $this->assertSame(['unisex'], $this->entities[1]['genderCategory']['value']);
        $this->assertArrayNotHasKey('genderCategory', $this->entities[0]);
        $this->assertArrayNotHasKey('wheelchairAccessible', $this->entities[1]);
    }

    public function testItKeepsACategoryTheMappingDoesNotRecognise(): void
    {
        $this->assertSame('ukendt', $this->entities[2]['additionalInformation']['value']['category']);
        $this->assertArrayNotHasKey('category', $this->entities[0]['additionalInformation']['value']);
    }

    public function testItMapsMannedOntoStaffed(): void
    {
        $this->assertFalse($this->entities[0]['staffed']['value']);
        $this->assertArrayNotHasKey('staffed', $this->entities[1]);
    }

    public function testItCarriesTheAdditionalAddressLine(): void
    {
        $this->assertSame('2. sal', $this->entities[1]['additionalInformation']['value']['addressAdditional']);
        $this->assertArrayNotHasKey('addressAdditional', $this->entities[0]['additionalInformation']['value']);
    }

    public function testItMapsTheTapOntoHandwashing(): void
    {
        $this->assertTrue($this->entities[0]['handwashing']['value']);
        $this->assertFalse($this->entities[1]['handwashing']['value']);
        $this->assertArrayNotHasKey('handwashing', $this->entities[2]);
        $this->assertArrayNotHasKey('tap', $this->entities[0]['additionalInformation']['value']);
    }

    public function testItPublishesTheContactAsTheFaultReportingContactPoint(): void
    {
        $this->assertSame(
            ['contactType' => 'fault reporting', 'email' => 'findtoilet@findtoilet.dk'],
            $this->entities[0]['contactPoint']['value']
        );
    }

    public function testItSplitsTheDescriptionIntoPlacementAndOpeningHours(): void
    {
        $additional = $this->entities[0]['additionalInformation']['value'];

        $this->assertSame('Tangkrogen', $additional['placement']);
        $this->assertSame('Hele året', $additional['openingHours']);
    }

    public function testItMapsTheFacilityCodesOntoTheModel(): void
    {
        // 0 is no and 1 is yes.
        $this->assertFalse($this->entities[1]['babyChange']['value']);
        $this->assertFalse($this->entities[1]['sharpsDisposal']['value']);
        $this->assertTrue($this->entities[2]['babyChange']['value']);
        $this->assertTrue($this->entities[2]['sharpsDisposal']['value']);
    }

    public function testItLeavesAFacilityCodedAsUnknownOut(): void
    {
        // 2 is the site's "unknown", which is not the same as no.
        $this->assertArrayNotHasKey('babyChange', $this->entities[0]);
        $this->assertArrayNotHasKey('sharpsDisposal', $this->entities[0]);

        $additional = $this->entities[0]['additionalInformation']['value'];
        $this->assertArrayNotHasKey('needleContainer', $additional);
        $this->assertArrayNotHasKey('changingTable', $additional);
    }

    public function testItMapsAnAbsentChargeOntoFreeAccess(): void
    {
        $this->assertTrue($this->entities[0]['isAccessibleForFree']['value']);
    }

    public function testItMapsAStatedChargeOntoPaidAccess(): void
    {
        $this->assertFalse($this->entities[1]['isAccessibleForFree']['value']);
    }

    public function testItOmitsFreeAccessWhenTheFeedStatesNoCharge(): void
    {
        // The third record carries no payment field at all; nothing is stated.
        $this->assertArrayNotHasKey('isAccessibleForFree', $this->entities[2]);
    }

    public function testItPublishesAPointGeometryFromLatAndLong(): void
    {
        $geometry = $this->entities[0]['location']['value'];

        $this->assertSame('Point', $geometry['type']);
        // The feed is already WGS84 — GeoJSON order, longitude first.
        $this->assertSame([10.207297, 56.139321], $geometry['coordinates']);
    }

    public function testItPublishesEveryImageUrl(): void
    {
        $this->assertSame(
            [
                'https://beta.findtoilet.dk/sites/default/files/images/5/2022/06/strandvejen19.jpg',
                'https://beta.findtoilet.dk/sites/default/files/images/5/2022/06/strandvejen19-tangkrogen.jpg',
            ],
            $this->entities[0]['additionalInformation']['value']['images']
        );
    }

    public function testItOmitsImageWhenARecordCarriesNone(): void
    {
        $this->assertArrayNotHasKey('images', $this->entities[1]['additionalInformation']['value'] ?? []);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function toilets(): array
    {
        return [
            [
                'id' => '862',
                'title' => 'Strandvejen 19',
                'description' => "<b>Placering:</b> Tangkrogen\r\n<b>Åbningstider:</b> Hele året",
                'location' => [
                    'street' => 'Strandvejen 19',
                    'additional' => '',
                    'city' => 'Aarhus',
                    'postal_code' => '8000',
                    'country' => 'dk',
                    'lat' => '56.139321',
                    'long' => '10.207297',
                ],
                'region' => ['term_id' => '8', 'name' => 'Aarhus'],
                'needle_container' => '2',
                'manned' => '0',
                'tap' => '1',
                'changing_table' => '2',
                'type' => 'handicap',
                'payment' => '0',
                'kontakt' => 'findtoilet@findtoilet.dk',
                'kontakttitle' => 'findtoilet@findtoilet.dk',
                'images' => [
                    ['mime_type' => 'image/jpeg', 'url' => 'https://beta.findtoilet.dk/sites/default/files/images/5/2022/06/strandvejen19.jpg'],
                    ['mime_type' => 'image/jpeg', 'url' => 'https://beta.findtoilet.dk/sites/default/files/images/5/2022/06/strandvejen19-tangkrogen.jpg'],
                ],
            ],
            [
                'id' => '913',
                'title' => 'Test uden billeder',
                'description' => '',
                'location' => [
                    'street' => 'Testvej 1',
                    // Constructed: blank throughout the live feed.
                    'additional' => '2. sal',
                    'city' => 'Aarhus',
                    'lat' => '56.15',
                    'long' => '10.20',
                ],
                'type' => 'unisex',
                'tap' => '0',
                'needle_container' => '0',
                'changing_table' => '0',
                // Constructed: the live feed states no charge anywhere.
                'payment' => '1',
                'images' => [],
            ],
            [
                'id' => '914',
                'title' => 'Test uden betalingsfelt',
                'description' => '',
                'location' => [
                    'street' => 'Testvej 2',
                    'city' => 'Aarhus',
                    'lat' => '56.16',
                    'long' => '10.21',
                ],
                // A category the mapping does not know.
                'type' => 'ukendt',
                // Constructed: the live feed has no record with 1 in both.
                'needle_container' => '1',
                'changing_table' => '1',
                // No payment field — nothing is stated about charging.
            ],
            [
                'id' => '999',
                'title' => 'Uden koordinater',
                'location' => [
                    'street' => 'Ukendt',
                ],
                // No lat/long — must be skipped.
            ],
        ];
    }
}
