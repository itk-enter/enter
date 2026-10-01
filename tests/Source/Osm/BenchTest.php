<?php

declare(strict_types=1);

namespace App\Tests\Source\Osm;

use App\Geo\Wgs84Transformer;
use App\Ngsi\NgsiEntity;
use App\Source\Osm\Bench;
use PHPUnit\Framework\TestCase;

/**
 * Covers the mapping only. Reading the feed is the reader's job.
 *
 * The first elements are real records from the captured export in
 * tests/resources/data; the rest are constructed for values the export does
 * not carry.
 */
class BenchTest extends TestCase
{
    private Bench $source;

    /** @var list<array<string, mixed>> */
    private array $entities;

    protected function setUp(): void
    {
        $this->source = new Bench();
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
        // Eleven elements, of which one has no id and one no coordinates.
        $this->assertCount(9, $this->entities);
    }

    public function testItAddressesEntitiesByOsmTypeAndId(): void
    {
        $this->assertSame(
            \sprintf('urn:ngsi-ld:%s:aarhus-bench-osm-node-841826867', $this->source->definition->model()),
            $this->entities[0]['id']
        );
        $this->assertSame(
            \sprintf('urn:ngsi-ld:%s:aarhus-bench-osm-way-522107919', $this->source->definition->model()),
            $this->entities[1]['id']
        );
    }

    public function testItTakesTheTypeFromTheSourceModel(): void
    {
        foreach ($this->entities as $entity) {
            $this->assertSame('Bench', $entity['type']);
        }
    }

    public function testItPublishesANodeAtItsOwnCoordinates(): void
    {
        $this->assertSame(
            ['type' => 'Point', 'coordinates' => [10.2325855, 56.2405115]],
            $this->entities[0]['location']['value']
        );
    }

    public function testItPublishesAWayAtItsCentre(): void
    {
        $this->assertSame(
            ['type' => 'Point', 'coordinates' => [10.2129106, 56.1532318]],
            $this->entities[1]['location']['value']
        );
    }

    public function testItMapsTheBenchTagsOntoTheModel(): void
    {
        $entity = $this->entities[0];

        $this->assertTrue($entity['backrest']['value']);
        $this->assertSame('wood', $entity['material']['value']);
        $this->assertSame('black', $entity['color']['value']);
        $this->assertSame(3, $entity['seats']['value']);
        $this->assertSame(256.0, $entity['direction']['value']);
    }

    public function testItOmitsWhatARecordDoesNotState(): void
    {
        // Tri-state: an absent tag is unknown, not false.
        $this->assertArrayNotHasKey('armrest', $this->entities[0]);
        $this->assertArrayNotHasKey('lyingDown', $this->entities[0]);
        $this->assertArrayNotHasKey('additionalInformation', $this->entities[0]);
    }

    public function testItMapsHostileArchitecture(): void
    {
        $this->assertSame(['slanted'], $this->entities[1]['hostileArchitecture']['value']);
        $this->assertTrue($this->entities[1]['armrest']['value']);
    }

    public function testItMapsSeparatedSeatsAndTheSurveyDate(): void
    {
        $entity = $this->entities[2];

        $this->assertFalse($entity['seatsSeparated']['value']);
        $this->assertFalse($entity['backrest']['value']);
        $this->assertSame('2025-01-09', $entity['checkDate']['value']);
    }

    public function testItMapsLevelAndLighting(): void
    {
        $entity = $this->entities[3];

        $this->assertSame(0.0, $entity['level']['value']);
        $this->assertFalse($entity['lit']['value']);
        $this->assertSame(4, $entity['seats']['value']);
    }

    public function testItMapsTheBinOntoAWasteBasket(): void
    {
        $this->assertTrue($this->entities[4]['wasteBasket']['value']);
        $this->assertSame('Bench', $this->entities[4]['name']['value']);
    }

    public function testItCarriesThePanoramaxIdAsAdditionalInformation(): void
    {
        $this->assertSame(
            ['panoramax' => 'df851d52-3032-4e23-b70e-b75ce41bc3ba'],
            $this->entities[5]['additionalInformation']['value']
        );
    }

    public function testItLeavesOutTheArtworkTagsOfAnArtworkBench(): void
    {
        $payload = json_encode($this->entities[6], \JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('Jeppe Hein', $payload);
        $this->assertStringNotContainsString('sculpture', $payload);
        $this->assertSame('metal', $this->entities[6]['material']['value']);
    }

    public function testItConvertsACompassPointToDegrees(): void
    {
        $this->assertSame(45.0, $this->entities[7]['direction']['value']);
    }

    public function testItTakesTheLaterOfTheTwoCheckDates(): void
    {
        $this->assertSame('2026-05-14', $this->entities[7]['checkDate']['value']);
    }

    public function testItMapsMemorialAndInscriptionAndCovered(): void
    {
        $entity = $this->entities[7];

        $this->assertTrue($entity['memorial']['value']);
        $this->assertSame('Vestereng er skøn, hold den ren og grøn', $entity['inscription']['value']);
        $this->assertTrue($entity['covered']['value']);
        $this->assertTrue($entity['twoSided']['value']);
        $this->assertTrue($entity['lyingDown']['value']);
    }

    public function testItMapsAnUnknownHostileFeatureToOtherAndIgnoresNo(): void
    {
        $this->assertSame(['spikes', 'other'], $this->entities[7]['hostileArchitecture']['value']);
    }

    public function testItCarriesValuesThatDoNotFitTheModelAsAdditionalInformation(): void
    {
        $entity = $this->entities[8];

        $this->assertArrayNotHasKey('material', $entity);
        $this->assertArrayNotHasKey('direction', $entity);
        $this->assertArrayNotHasKey('seats', $entity);
        $this->assertSame(
            [
                'material' => 'bamboo',
                'direction' => 'forward',
                'seats' => 'many',
                // Tags neither mapped nor omitted, under their OSM keys.
                'otherTags' => ['backrest:material' => 'metal'],
            ],
            $entity['additionalInformation']['value']
        );
    }

    public function testItRecordsTheProviderTheElementAndTheFeed(): void
    {
        $entity = $this->entities[0];

        $this->assertSame('OpenStreetMap contributors', $entity['dataProvider']['value']);
        $this->assertSame('https://www.openstreetmap.org/node/841826867', $entity['seeAlso']['value']);
        $this->assertStringStartsWith('https://overpass-api.de/api/interpreter?data=', $entity['source']['value']);
        $this->assertStringContainsString(rawurlencode('nwr["amenity"="bench"](area.a);'), $entity['source']['value']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function elements(): array
    {
        return [
            [
                'type' => 'node',
                'id' => 841826867,
                'lat' => 56.2405115,
                'lon' => 10.2325855,
                'tags' => ['amenity' => 'bench', 'backrest' => 'yes', 'colour' => 'black', 'direction' => '256', 'material' => 'wood', 'seats' => '3'],
            ],
            [
                'type' => 'way',
                'id' => 522107919,
                'center' => ['lat' => 56.1532318, 'lon' => 10.2129106],
                'tags' => ['amenity' => 'bench', 'armrest' => 'yes', 'backrest' => 'yes', 'hostile_architecture' => 'slanted', 'material' => 'wood'],
            ],
            [
                'type' => 'node',
                'id' => 12293431739,
                'lat' => 56.1540381,
                'lon' => 10.2063016,
                'tags' => ['amenity' => 'bench', 'armrest' => 'no', 'backrest' => 'no', 'direction' => '310', 'material' => 'wood', 'seats:separated' => 'no', 'survey:date' => '2025-01-09'],
            ],
            [
                'type' => 'node',
                'id' => 12695179466,
                'lat' => 56.2392928,
                'lon' => 10.2302574,
                'tags' => ['access' => 'yes', 'amenity' => 'bench', 'armrest' => 'no', 'backrest' => 'yes', 'direction' => '149', 'level' => '0', 'lit' => 'no', 'material' => 'wood', 'seats' => '4', 'source' => 'survey'],
            ],
            [
                'type' => 'node',
                'id' => 13176423847,
                'lat' => 56.1802071,
                'lon' => 10.1583304,
                'tags' => ['amenity' => 'bench', 'bin' => 'yes', 'name' => 'Bench'],
            ],
            [
                'type' => 'node',
                'id' => 8151205541,
                'lat' => 56.1201844,
                'lon' => 10.2270079,
                'tags' => ['amenity' => 'bench', 'panoramax' => 'df851d52-3032-4e23-b70e-b75ce41bc3ba'],
            ],
            [
                'type' => 'node',
                'id' => 11780992598,
                'lat' => 56.1566599,
                'lon' => 10.214153,
                'tags' => ['amenity' => 'bench', 'armrest' => 'no', 'artist_name' => 'Jeppe Hein', 'artwork_type' => 'sculpture', 'backrest' => 'yes', 'material' => 'metal', 'name' => 'Bænk', 'tourism' => 'artwork'],
            ],
            [
                'type' => 'node',
                'id' => 888668859,
                'lat' => 56.1841591,
                'lon' => 10.1837388,
                // Constructed: values the export does not carry.
                'tags' => [
                    'amenity' => 'bench',
                    'direction' => 'NE',
                    'check_date' => '2026-05-14',
                    'survey:date' => '2023-06-04',
                    'memorial' => 'bench',
                    'inscription' => 'Vestereng er skøn, hold den ren og grøn',
                    'covered' => 'yes',
                    'two_sided' => 'yes',
                    'lying_down' => 'yes',
                    'hostile_architecture' => 'spikes;no;bars',
                    'not:tourism:artwork' => 'yes',
                ],
            ],
            [
                'type' => 'node',
                'id' => 999000001,
                'lat' => 56.15,
                'lon' => 10.20,
                // Constructed: values the model has no term for.
                'tags' => [
                    'amenity' => 'bench',
                    'material' => 'bamboo',
                    'direction' => 'forward',
                    'seats' => 'many',
                    'backrest:material' => 'metal',
                ],
            ],
            [
                'type' => 'node',
                'lat' => 56.16,
                'lon' => 10.21,
                'tags' => ['amenity' => 'bench'],
                // No id — must be skipped.
            ],
            [
                'type' => 'node',
                'id' => 999000002,
                'tags' => ['amenity' => 'bench'],
                // No coordinates — must be skipped.
            ],
        ];
    }
}
