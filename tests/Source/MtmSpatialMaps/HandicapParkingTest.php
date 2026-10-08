<?php

declare(strict_types=1);

namespace App\Tests\Source\MtmSpatialMaps;

use App\Geo\Wgs84Transformer;
use App\Source\MtmSpatialMaps\AbstractHandicapParking;
use App\Source\MtmSpatialMaps\OnStreetParking;
use App\Source\MtmSpatialMaps\ParkingSpot;
use PHPUnit\Framework\TestCase;

/**
 * Covers the mapping only.
 *
 * The two sources read one feed and each keeps the records of its own
 * model, so they are covered together: what one source publishes is only
 * right if the other skips it.
 */
class HandicapParkingTest extends TestCase
{
    /** @var list<AbstractHandicapParking> */
    private array $sources;

    /**
     * The entity published for each record, in feed order, from whichever
     * source published it.
     *
     * @var list<array<string, mixed>>
     */
    private array $entities;

    /**
     * For each record, the ids of the sources that published it.
     *
     * @var list<list<string>>
     */
    private array $publishedBy;

    protected function setUp(): void
    {
        $this->sources = [new ParkingSpot(), new OnStreetParking()];
        $transformer = new Wgs84Transformer();

        $this->entities = [];
        $this->publishedBy = [];
        foreach ($this->features() as $data) {
            $publishedBy = [];
            foreach ($this->sources as $source) {
                $entity = $source->createNgsiEntity($data, $transformer);
                if (null !== $entity) {
                    $publishedBy[] = $source->definition->id;
                    $this->entities[] = $entity->toPayload(['https://example.com/context.jsonld']);
                }
            }
            $this->publishedBy[] = $publishedBy;
        }
    }

    public function testItSkipsRecordsWithoutAKeyOrGeometry(): void
    {
        // Six features, of which one has no key and one no geometry.
        $this->assertCount(4, $this->entities);
        $this->assertSame([], $this->publishedBy[4]);
        $this->assertSame([], $this->publishedBy[5]);
    }

    public function testEveryRecordIsPublishedByExactlyOneSource(): void
    {
        foreach (\array_slice($this->publishedBy, 0, 4) as $record => $publishedBy) {
            $this->assertCount(1, $publishedBy, \sprintf('Record %d', $record));
        }
    }

    public function testEachSourcePublishesOneModel(): void
    {
        $models = [];
        foreach ($this->sources as $source) {
            $models[$source->definition->id] = $source->definition->model;
        }

        $this->assertSame([
            'mtm_spatialmaps-handicap-parking-spot' => 'ParkingSpot',
            'mtm_spatialmaps-handicap-parking-on-street' => 'OnStreetParking',
        ], $models);
    }

    public function testTheSourcesReadOneFeed(): void
    {
        $urls = array_map(static fn (AbstractHandicapParking $source): string => $source->definition->accessUrlWithQuery(), $this->sources);

        $this->assertCount(1, array_unique($urls));
    }

    public function testTheSourcesBelongToOneDataSet(): void
    {
        $datasets = array_map(static fn (AbstractHandicapParking $source): array => [$source->definition->dataset, $source->definition->datasetTitle], $this->sources);

        $this->assertSame([['mtm_spatialmaps-handicap-parking', 'Handicapparkering (MTM), Aarhus Kommune']], array_values(array_unique($datasets, \SORT_REGULAR)));
    }

    public function testItTypesARecordOfOneBayAsABay(): void
    {
        $this->assertSame(['mtm_spatialmaps-handicap-parking-spot'], $this->publishedBy[0]);
        $this->assertSame('ParkingSpot', $this->entities[0]['type']);
        $this->assertSame('urn:ngsi-ld:ParkingSpot:aarhus-handicap-179', $this->entities[0]['id']);
    }

    public function testItTypesARecordOfSeveralBaysAsASite(): void
    {
        $this->assertSame(['mtm_spatialmaps-handicap-parking-on-street'], $this->publishedBy[1]);
        $this->assertSame('OnStreetParking', $this->entities[1]['type']);
        $this->assertSame('urn:ngsi-ld:OnStreetParking:aarhus-handicap-172', $this->entities[1]['id']);
        $this->assertSame(6, $this->entities[1]['totalSpotNumber']['value']);
    }

    public function testItReadsABlankCountAsOneBay(): void
    {
        // The register's grain is the bay; a record without a count is a bay
        // entered without one, not a site of zero or unknown size.
        $this->assertSame('ParkingSpot', $this->entities[2]['type']);
        $this->assertSame('urn:ngsi-ld:ParkingSpot:aarhus-handicap-516', $this->entities[2]['id']);
        $this->assertArrayNotHasKey('totalSpotNumber', $this->entities[2]);
    }

    public function testItReadsACountTheFeedStatesAsAString(): void
    {
        $this->assertSame('OnStreetParking', $this->entities[3]['type']);
        $this->assertSame(2, $this->entities[3]['totalSpotNumber']['value']);
    }

    public function testItPublishesABayOnTheStreetWithUnknownStatus(): void
    {
        $this->assertSame('unknown', $this->entities[0]['status']['value']);
        $this->assertSame(['onStreet'], $this->entities[0]['category']['value']);
        $this->assertArrayNotHasKey('totalSpotNumber', $this->entities[0]);
    }

    public function testItMarksEverySiteAsDisabledParking(): void
    {
        foreach ([1, 3] as $site) {
            $this->assertSame(['forDisabled'], $this->entities[$site]['category']['value']);
            $this->assertArrayNotHasKey('status', $this->entities[$site]);
        }
    }

    public function testItNamesARecordByItsAddress(): void
    {
        $this->assertSame('Klostergade 2 56', $this->entities[0]['name']['value']);
        $this->assertSame('Domkirkeplads/Bispegade 1', $this->entities[1]['name']['value']);

        // A blank house number leaves just the street.
        $this->assertSame('Kystvejen', $this->entities[2]['name']['value']);
    }

    public function testItPublishesTheNoteAsTheDescriptionWhenThereIsOne(): void
    {
        $this->assertSame('Tidsbegrænset', $this->entities[3]['description']['value']);
        $this->assertArrayNotHasKey('description', $this->entities[0]);
    }

    public function testItReprojectsTheLocationToWgs84(): void
    {
        $location = $this->entities[1]['location'];

        $this->assertSame('GeoProperty', $location['type']);
        $this->assertSame('Point', $location['value']['type']);

        // The feed is ETRS89 / UTM zone 32N; Domkirkeplads lands in the
        // centre of Aarhus.
        $this->assertEqualsWithDelta(10.21, $location['value']['coordinates'][0], 0.01);
        $this->assertEqualsWithDelta(56.157, $location['value']['coordinates'][1], 0.01);
    }

    public function testItRecordsTheAccessUrlAsTheEntitySource(): void
    {
        $this->assertSame($this->sources[0]->definition->accessUrl, $this->entities[0]['source']['value']);
    }

    /**
     * Four records shaped like the live feed — one bay, a site of six, a
     * bay with a blank count, and a count stated as a string — followed by
     * the two guards that discard a record.
     *
     * @return list<array<string, mixed>>
     */
    private function features(): array
    {
        return [
            $this->feature(179, 'Klostergade 2', '56', 1),
            $this->feature(172, 'Domkirkeplads/Bispegade', '1', 6, x: 575180.0, y: 6224310.0),
            $this->feature(516, 'Kystvejen', null, null),
            $this->feature(183, 'Kannikegade', '16A', '2', note: 'Tidsbegrænset'),
            $this->feature(null, 'Gade', '1', 1),
            [
                'type' => 'Feature',
                'geometry' => null,
                'properties' => ['mi_prinx' => 999, 'vejnavn' => 'Gade', 'husnnr' => '1', 'invalidepladser' => 1],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function feature(?int $key, string $street, ?string $number, int|string|null $bays, ?string $note = null, float $x = 575000.0, float $y = 6225000.0): array
    {
        return [
            'type' => 'Feature',
            'geometry' => ['type' => 'Point', 'coordinates' => [$x, $y]],
            'properties' => [
                'mi_prinx' => $key,
                'vejnavn' => $street,
                'husnnr' => $number,
                'invalidepladser' => $bays,
                'bemrk' => $note,
                'ident' => 'P',
            ],
        ];
    }
}
