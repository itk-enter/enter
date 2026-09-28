<?php

declare(strict_types=1);

namespace App\Tests\Source\MtmSpatialMaps;

use App\Geo\Wgs84Transformer;
use App\Ngsi\NgsiEntity;
use App\Source\MtmSpatialMaps\ToiletCity;
use PHPUnit\Framework\TestCase;

/**
 * Covers the mapping only. Reading the feed is the reader's job.
 */
class ToiletCityTest extends TestCase
{
    private ToiletCity $source;

    /** @var list<array<string, mixed>> */
    private array $entities;

    protected function setUp(): void
    {
        $this->source = new ToiletCity();
        $transformer = new Wgs84Transformer();
        $this->entities = array_values(array_map(
            static fn (NgsiEntity $entity): array => $entity->toPayload(['https://example.com/context.jsonld']),
            array_filter(array_map(
                fn (array $data) => $this->source->createNgsiEntity($data, $transformer),
                $this->features()
            ))
        ));
    }

    public function testItSkipsRecordsWithoutAPrimaryKey(): void
    {
        // Three features, one without mi_prinx.
        $this->assertCount(2, $this->entities);
    }

    public function testItAddressesEntitiesByThePrimaryKey(): void
    {
        $this->assertSame(
            \sprintf('urn:ngsi-ld:%s:aarhus-toilet-city-3', $this->source->definition->model),
            $this->entities[0]['id']
        );
    }

    public function testItFallsBackFromABlankNameToPlaceringsinfo(): void
    {
        // navn is blank; placeringsinfo names the spot.
        $this->assertSame('v/Skolebakken v/Havnens P-Plads', $this->entities[1]['name']['value']);
    }

    public function testItFallsBackToTheAddressWhenNeitherNameNorPlacementIsGiven(): void
    {
        $this->assertSame('Banegårdspladsen 4A', $this->entities[0]['name']['value']);
    }

    public function testItPublishesAPointGeometry(): void
    {
        $geometry = $this->entities[0]['location']['value'];
        $this->assertSame('Point', $geometry['type']);
    }

    public function testItPublishesAMultiPointGeometry(): void
    {
        $geometry = $this->entities[1]['location']['value'];
        $this->assertSame('MultiPoint', $geometry['type']);
    }

    public function testItPublishesNoDescription(): void
    {
        // This feed carries no accessibility/category signal at all.
        $this->assertArrayNotHasKey('description', $this->entities[0]);
    }

    public function testItCarriesStatusAndTheRegisterTimestampsAsAdditionalInformation(): void
    {
        $this->assertSame(
            [
                'status' => 'Aktiv',
                'registeredAt' => '2018-10-01 13:12:00.333',
                'updatedAt' => '2018-12-03 11:47:37.597',
            ],
            $this->entities[0]['additionalInformation']['value']
        );
    }

    public function testItCarriesOnlyTheTimestampsARecordActuallyHas(): void
    {
        // The second record has never been edited.
        $this->assertSame(
            ['status' => 'Aktiv', 'registeredAt' => '2018-10-01 13:12:00.333'],
            $this->entities[1]['additionalInformation']['value']
        );
    }

    public function testItDoesNotPublishTheEmployeeUsernames(): void
    {
        // oprettet_af and rettet_af are personal data; the feed carries them
        // on every record and nothing published may restate them. The
        // usernames here are placeholders: the real ones do not belong in a
        // committed test.
        $payload = json_encode($this->entities, \JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('az00000', $payload);
        $this->assertStringNotContainsString('ADM', $payload);
        $this->assertStringNotContainsString('spatial_reader', $payload);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function features(): array
    {
        return [
            [
                'type' => 'Feature',
                'geometry' => ['type' => 'Point', 'coordinates' => [574856.3599744864, 6223527.5789597845]],
                'properties' => [
                    'status' => 'Aktiv',
                    'familie' => 'Toilet',
                    'subfamilie' => 'TOI Cox',
                    'navn' => ' ',
                    'adresse' => 'Banegårdspladsen 4A',
                    'placeringsinfo' => ' ',
                    'oprettet_af' => 'az00000',
                    'oprettet_dato' => '2018-10-01 13:12:00.333',
                    'rettet_af' => 'ADM\\az00000',
                    'rettet_dato' => '2018-12-03 11:47:37.597',
                    'mi_prinx' => 3,
                ],
            ],
            [
                'type' => 'Feature',
                'geometry' => ['type' => 'MultiPoint', 'coordinates' => [[575347.8648020709, 6224178.899330701]]],
                'properties' => [
                    'status' => 'Aktiv',
                    'familie' => 'Toilet',
                    'subfamilie' => 'TOI Cox',
                    'navn' => ' ',
                    'adresse' => 'Skolebakken 6H',
                    'placeringsinfo' => 'v/Skolebakken v/Havnens P-Plads',
                    'oprettet_af' => 'az00000',
                    'oprettet_dato' => '2018-10-01 13:12:00.333',
                    'rettet_af' => 'spatial_reader',
                    // No rettet_dato — the record has never been edited.
                    'mi_prinx' => 5,
                ],
            ],
            [
                'type' => 'Feature',
                'geometry' => ['type' => 'Point', 'coordinates' => [574549.8536961579, 6223567.220890785]],
                'properties' => [
                    'status' => 'Aktiv',
                    'navn' => ' ',
                    'adresse' => 'Frederiks Alle 20A',
                    // No mi_prinx — must be skipped.
                ],
            ],
        ];
    }
}
