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

    public function testItPublishesTheTitleAsNameAndTheStreetAsAddress(): void
    {
        $this->assertSame('Strandvejen 19', $this->entities[0]['name']['value']);
        $this->assertSame('Strandvejen 19', $this->entities[0]['address']['value']);
    }

    public function testItSplitsTheDescriptionIntoPlacementAndOpeningHours(): void
    {
        $additional = $this->entities[0]['additionalInformation']['value'];

        $this->assertSame('Tangkrogen', $additional['placement']);
        $this->assertSame('Hele året', $additional['openingHours']);
        $this->assertSame('handicap', $additional['category']);
        $this->assertSame('1', $additional['tap']);
    }

    public function testItCarriesTheFacilityCodesTheModelCannotHold(): void
    {
        $additional = $this->entities[0]['additionalInformation']['value'];

        // The feed's 0/1/2 codes are undocumented and carried verbatim.
        $this->assertSame('2', $additional['needleContainer']);
        $this->assertSame('2', $additional['changingTable']);
        $this->assertSame('0', $additional['manned']);
        $this->assertSame('findtoilet@findtoilet.dk', $additional['contact']);
        $this->assertSame('findtoilet@findtoilet.dk', $additional['contactTitle']);
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
            $this->entities[0]['image']['value']
        );
    }

    public function testItOmitsImageWhenARecordCarriesNone(): void
    {
        $this->assertArrayNotHasKey('image', $this->entities[1]);
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
                    'city' => 'Aarhus',
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
                    'city' => 'Aarhus',
                    'lat' => '56.15',
                    'long' => '10.20',
                ],
                'type' => 'unisex',
                'tap' => '0',
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
                'type' => 'unisex',
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
