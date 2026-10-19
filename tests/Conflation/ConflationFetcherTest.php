<?php

declare(strict_types=1);

namespace App\Tests\Conflation;

use App\Broker\NgsiLdBroker;
use App\Conflation\Augmentation\AbstractAugmentation;
use App\Conflation\Cluster;
use App\Conflation\Conflation;
use App\Conflation\ConflationFetcher;
use App\Conflation\ConflictResolver;
use App\Conflation\MergeByLocation\OneToOneClosestFirst;
use App\Conflation\Record;
use App\Source\FindToilet\PublicToilet as FindToiletPublicToilet;
use App\Source\Osm\Bench;
use App\Source\Osm\PublicToilet as OsmPublicToilet;
use App\Source\SourceInterface;
use App\SourceManager;
use App\Test\Source\Osm\TestPublicToilet;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class ConflationFetcherTest extends TestCase
{
    /**
     * The source ids the broker was asked for.
     *
     * @var list<string>
     */
    private array $asked = [];

    /**
     * A fetcher over two toilet data sets, a test toilet data set and a bench
     * data set, reading from a broker that answers with the given features
     * for each source id.
     *
     * @param array<string, list<array<string, mixed>>> $features
     */
    private function fetcher(array $features): ConflationFetcher
    {
        $client = new MockHttpClient(function (string $method, string $url) use ($features): MockResponse {
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            preg_match('/sourceId=="([^"]+)"/', (string) $query['q'], $match);
            $this->asked[] = $match[1];

            return new MockResponse(json_encode(['type' => 'FeatureCollection', 'features' => $features[$match[1]] ?? []], \JSON_THROW_ON_ERROR));
        });

        /** @var list<SourceInterface> $sources */
        $sources = [new OsmPublicToilet(), new FindToiletPublicToilet(), new TestPublicToilet(), new Bench()];

        return new ConflationFetcher(new SourceManager($sources), new NgsiLdBroker($client, 'http://broker.example'), new NullLogger());
    }

    /**
     * @param array<string, mixed> $properties
     * @param array<string, mixed> $geometry
     *
     * @return array<string, mixed>
     */
    private static function feature(string $id, array $properties, array $geometry = ['type' => 'Point', 'coordinates' => [10.21, 56.15]]): array
    {
        return ['id' => $id, 'type' => 'Feature', 'geometry' => $geometry, 'properties' => $properties];
    }

    private static function conflation(string $id = 'merged-public-toilets'): Conflation
    {
        return new Conflation(
            id: $id,
            title: 'Merged toilets',
            description: 'Toilets merged from several data sets.',
            resultModel: 'PublicToilet',
            defining: ['PublicToilet'],
            mergeByLocation: new OneToOneClosestFirst(radius: 10.0),
        );
    }

    public function testItReadsEveryDataSetOfTheMergesModels(): void
    {
        $records = $this->fetcher([
            'osm-public-toilet' => [self::feature('urn:osm-2', ['name' => 'Park toilet'])],
            'findtoilet-public-toilet' => [self::feature('urn:findtoilet-1', ['name' => 'Parktoilet'])],
        ])->fetch(self::conflation());

        // Ordered by data set, then id.
        $this->assertSame(['urn:findtoilet-1', 'urn:osm-2'], array_map(static fn (Record $record): string => $record->id, $records));
        $this->assertSame(['findtoilet-public-toilet', 'osm-public-toilet'], array_map(static fn (Record $record): string => $record->sourceId, $records));
        $this->assertSame('PublicToilet', $records[0]->model);
        $this->assertSame([10.21, 56.15], [$records[0]->longitude, $records[0]->latitude]);

        // Neither the bench data set nor the test toilets are read.
        $this->assertSame(['findtoilet-public-toilet', 'osm-public-toilet'], $this->asked);
    }

    public function testATestMergeReadsOnlyTestDataSets(): void
    {
        $records = $this->fetcher([
            'test:osm-public-toilet' => [self::feature('urn:test-1', [])],
        ])->fetch(self::conflation('test:merged-public-toilets'));

        $this->assertSame(['test:osm-public-toilet'], $this->asked);
        $this->assertSame(['urn:test-1'], array_map(static fn (Record $record): string => $record->id, $records));
    }

    public function testItLeavesOutWhatDescribesTheDataSetRatherThanTheThing(): void
    {
        $records = $this->fetcher([
            'osm-public-toilet' => [self::feature('urn:osm-2', [
                'type' => 'PublicToilet',
                'location' => ['type' => 'Point', 'coordinates' => [10.21, 56.15]],
                'sourceId' => 'osm-public-toilet',
                'source' => 'https://overpass-api.de/api/interpreter',
                'additionalInformation' => ['check_date' => '2025-06-01'],
                'name' => 'Park toilet',
                'fee' => false,
                'wheelchairAccessible' => null,
            ])],
        ])->fetch(self::conflation());

        $this->assertSame(['name' => 'Park toilet', 'fee' => false, 'additionalInformation.check_date' => '2025-06-01'], $records[0]->attributes);
    }

    public function testEachKeyOfAdditionalInformationIsSettledOnItsOwn(): void
    {
        $records = $this->fetcher([
            'osm-public-toilet' => [self::feature('urn:osm-2', ['additionalInformation' => ['check_date' => '2025-06-01', 'operator' => 'Aarhus Kommune']])],
            'findtoilet-public-toilet' => [self::feature('urn:findtoilet-1', ['additionalInformation' => ['operator' => 'Aarhus Kommune', 'manned' => 'no']])],
        ])->fetch(self::conflation());

        $entity = new ConflictResolver([])->resolve(new Cluster($records));

        // The data sets agree on the operator, and each states a key the
        // other does not, so nothing is in conflict.
        $this->assertSame([
            'additionalInformation.check_date' => '2025-06-01',
            'additionalInformation.manned' => 'no',
            'additionalInformation.operator' => 'Aarhus Kommune',
        ], $entity->attributes);
        $this->assertSame([], $entity->conflicts);
    }

    public function testAdditionalInformationThatIsNotKeyedIsKeptWhole(): void
    {
        $records = $this->fetcher([
            'osm-public-toilet' => [self::feature('urn:osm-2', ['additionalInformation' => ['first', 'second']])],
        ])->fetch(self::conflation());

        $this->assertSame(['additionalInformation' => ['first', 'second']], $records[0]->attributes);
    }

    public function testAnAreaKeepsItsShape(): void
    {
        $area = ['type' => 'Polygon', 'coordinates' => [[[10.21, 56.15], [10.214, 56.15], [10.214, 56.152], [10.21, 56.152], [10.21, 56.15]]]];

        $records = $this->fetcher(['osm-public-toilet' => [self::feature('urn:osm-way-3', [], $area)]])->fetch(self::conflation());

        $this->assertSame($area, $records[0]->geometry);
    }

    public function testAnEntityWithoutAGeometryIsLeftOut(): void
    {
        $records = $this->fetcher([
            'osm-public-toilet' => [['id' => 'urn:osm-4', 'type' => 'Feature', 'geometry' => null, 'properties' => []]],
        ])->fetch(self::conflation());

        $this->assertSame([], $records);
    }

    public function testItReadsTheAugmentingModelsToo(): void
    {
        $conflation = new Conflation(
            id: 'merged-public-toilets',
            title: 'Merged toilets',
            description: 'Toilets merged from several data sets.',
            resultModel: 'PublicToilet',
            defining: ['PublicToilet'],
            mergeByLocation: new OneToOneClosestFirst(radius: 10.0),
            augmenting: ['Bench' => ['material']],
            augmentation: new class(10.0) extends AbstractAugmentation {
                public function augment(array $entities, array $records, array $attributesByModel): array
                {
                    return [];
                }
            },
        );

        $dataSets = $this->fetcher([])->dataSets($conflation);

        $this->assertSame(
            ['findtoilet-public-toilet', 'osm-bench', 'osm-public-toilet'],
            array_map(static fn (SourceInterface $source): string => $source->definition->id, $dataSets),
        );
    }
}
