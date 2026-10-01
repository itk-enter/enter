<?php

declare(strict_types=1);

namespace App\Tests\Source\MtmSpatialMaps;

use App\Geo\Wgs84Transformer;
use App\Ngsi\NgsiEntity;
use App\Source\MtmSpatialMaps\ToiletOther;
use PHPUnit\Framework\TestCase;

/**
 * Covers the mapping only. Reading the feed is the reader's job.
 */
class ToiletOtherTest extends TestCase
{
    private ToiletOther $source;

    /** @var list<array<string, mixed>> */
    private array $entities;

    protected function setUp(): void
    {
        $this->source = new ToiletOther();
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
        // Five features, one without mi_prinx.
        $this->assertCount(4, $this->entities);
    }

    public function testItAddressesEntitiesByThePrimaryKey(): void
    {
        $this->assertSame(
            \sprintf('urn:ngsi-ld:%s:aarhus-toilet-other-1', $this->source->definition->model()),
            $this->entities[0]['id']
        );
    }

    public function testItTakesTheTypeFromTheSourceModel(): void
    {
        foreach ($this->entities as $entity) {
            $this->assertSame($this->source->definition->model(), $entity['type']);
        }
    }

    public function testItPublishesTheNameAndDescriptionAndAddress(): void
    {
        $this->assertSame('Ørnereden', $this->entities[0]['name']['value']);
        $this->assertSame('Handicaptoilet', $this->entities[0]['description']['value']);
        $this->assertSame(['streetAddress' => 'Ørneredevej 55'], $this->entities[0]['address']['value']);
    }

    public function testItMapsAHandicapToiletOntoWheelchairAccess(): void
    {
        $this->assertSame('yes', $this->entities[0]['wheelchairAccessible']['value']);
    }

    public function testItLeavesWheelchairAccessUnknownWhenTheDescriptionStatesNone(): void
    {
        // Not being described as a handicap toilet is not the same as "no".
        $this->assertArrayNotHasKey('wheelchairAccessible', $this->entities[1]);
    }

    public function testItMapsFreeAccessOntoPublicAccess(): void
    {
        $this->assertSame('public', $this->entities[0]['accessType']['value']);
        $this->assertArrayNotHasKey('accessNote', $this->entities[0]);
    }

    public function testItCarriesARestrictedAccessAsAnAccessNote(): void
    {
        $this->assertArrayNotHasKey('accessType', $this->entities[1]);
        $this->assertSame('SMS-låst', $this->entities[1]['accessNote']['value']);
    }

    public function testItMapsTheUrinalDescriptionsOntoToiletPosition(): void
    {
        $this->assertSame(['seated', 'urinal'], $this->entities[2]['toiletPosition']['value']);
        $this->assertArrayNotHasKey('toiletPosition', $this->entities[0]);
    }

    public function testItMapsARentedCabinOntoAPortableToilet(): void
    {
        $this->assertSame('portable', $this->entities[3]['toiletType']['value']);
        $this->assertArrayNotHasKey('toiletType', $this->entities[0]);
    }

    public function testItCarriesSeasonAndTheRegisterTimestampsAsAdditionalInformation(): void
    {
        $this->assertSame(
            [
                'season' => 'Hele året',
                'registeredAt' => '2018-10-01 13:11:49.08',
                'updatedAt' => '2022-06-02 09:12:08.087',
            ],
            $this->entities[0]['additionalInformation']['value']
        );
    }

    public function testItCarriesOnlyTheTimestampsARecordActuallyHas(): void
    {
        // The second record carries no edit timestamp.
        $this->assertSame(
            ['season' => 'Vinterlukket', 'registeredAt' => '2018-10-01 13:11:49.08'],
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
        $this->assertStringNotContainsString('spatial_reader', $payload);
    }

    public function testItPublishesAMultiPointGeometry(): void
    {
        $geometry = $this->entities[0]['location']['value'];

        $this->assertSame('MultiPoint', $geometry['type']);

        // The feed is EPSG:25832; a known point reprojects to a known WGS84
        // position, in GeoJSON order — longitude first.
        $this->assertEqualsWithDelta(10.2368, $geometry['coordinates'][0][0], 1e-3);
        $this->assertEqualsWithDelta(56.1012, $geometry['coordinates'][0][1], 1e-3);
    }

    public function testItRecordsTheAccessUrlAsTheEntitySource(): void
    {
        $this->assertSame($this->source->definition->accessUrlWithQuery(), $this->entities[0]['source']['value']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function features(): array
    {
        return [
            [
                'type' => 'Feature',
                'geometry' => ['type' => 'MultiPoint', 'coordinates' => [[576933.0181395365, 6218035.22691216]]],
                'properties' => [
                    'bookbar' => 'Nej',
                    'type' => 'Fri',
                    'navn' => 'Ørnereden',
                    'beskrivelse' => 'Handicaptoilet',
                    'adresse' => 'Ørneredevej 55',
                    'saeson' => 'Hele året',
                    'oprettet_af' => 'az00000',
                    'oprettet_dato' => '2018-10-01 13:11:49.08',
                    'rettet_af' => 'spatial_reader',
                    'rettet_dato' => '2022-06-02 09:12:08.087',
                    'mi_prinx' => 1,
                ],
            ],
            [
                'type' => 'Feature',
                'geometry' => ['type' => 'MultiPoint', 'coordinates' => [[578840.174729237, 6212728.077167142]]],
                'properties' => [
                    'bookbar' => 'Nej',
                    'type' => 'SMS-låst',
                    'navn' => 'Mariendal Strand',
                    'beskrivelse' => 'Primitivt skovtoilet',
                    'adresse' => 'Ørnevænget',
                    'saeson' => 'Vinterlukket',
                    'oprettet_af' => 'az00000',
                    'oprettet_dato' => '2018-10-01 13:11:49.08',
                    'rettet_af' => 'spatial_reader',
                    // No rettet_dato — the record has never been edited.
                    'mi_prinx' => 2,
                ],
            ],
            [
                'type' => 'Feature',
                'geometry' => ['type' => 'MultiPoint', 'coordinates' => [[579126.6271142375, 6211088.060987693]]],
                'properties' => [
                    'bookbar' => 'Nej',
                    'type' => 'Fri',
                    'navn' => 'Ajstrup Strand Nord',
                    'beskrivelse' => 'Handicaptoilet',
                    'adresse' => 'Ajstrup Strand, Nord',
                    'saeson' => 'Vinterlukket',
                    // No mi_prinx — must be skipped.
                ],
            ],
            [
                'type' => 'Feature',
                'geometry' => ['type' => 'MultiPoint', 'coordinates' => [[574856.36, 6223527.58]]],
                'properties' => [
                    'type' => 'Fri',
                    'navn' => 'Mølleparken',
                    'beskrivelse' => 'Toilet og urinal',
                    'mi_prinx' => 3,
                ],
            ],
            [
                'type' => 'Feature',
                'geometry' => ['type' => 'MultiPoint', 'coordinates' => [[575347.86, 6224178.90]]],
                'properties' => [
                    'type' => 'Fri',
                    'navn' => 'Den Permanente',
                    'beskrivelse' => 'Indlejet toiletkabine',
                    'mi_prinx' => 4,
                ],
            ],
        ];
    }
}
