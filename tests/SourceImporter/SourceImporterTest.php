<?php

declare(strict_types=1);

namespace App\Tests\SourceImporter;

use App\Broker\NgsiLdBroker;
use App\Broker\Rejection;
use App\Geo\Wgs84Transformer;
use App\Import\ImportResult;
use App\Ngsi\NgsiEntity;
use App\Source\AbstractSource;
use App\Source\DataType;
use App\Source\Definition;
use App\Source\MtmSpatialMaps\OnStreetParking;
use App\Source\Osm\Bench;
use App\Source\SourceInterface;
use App\SourceImporter\GetJsonSourceImporter;
use App\SourceImporter\OverpassSourceImporter;
use App\SourceReader\SourceReaderInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests the import of a source into the broker.
 */
class SourceImporterTest extends TestCase
{
    /**
     * @var list<array{string, string, array<string, mixed>}>
     */
    private array $requests = [];

    /**
     * Makes sure that each entity gets the id of its source.
     */
    public function testItStampsEveryEntityWithTheIdOfItsSource(): void
    {
        $source = new OnStreetParking();

        $this->import($source, $this->features(1, 2));

        $entities = $this->upserted();
        $this->assertCount(2, $entities);
        foreach ($entities as $entity) {
            $this->assertSame(
                ['type' => 'Property', 'value' => $source->definition->id],
                $entity['sourceId'],
            );
        }
    }

    /**
     * Makes sure that the import refuses an entity of a model that the source does not declare.
     */
    public function testItRefusesAnEntityOfAModelTheSourceDoesNotDeclare(): void
    {
        $source = new UndeclaredModelSource();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('ParkingSpot');

        $this->import($source, $this->features(1));
    }

    /**
     * Makes sure that the import deletes the entities that the source no longer yields.
     */
    public function testItDeletesTheEntitiesOfTheSourceTheImportNoLongerYields(): void
    {
        $stale = 'urn:ngsi-ld:OnStreetParking:aarhus-handicap-9';

        $result = $this->import(new OnStreetParking(), $this->features(1, 2), held: [
            'urn:ngsi-ld:OnStreetParking:aarhus-handicap-1',
            $stale,
            'urn:ngsi-ld:OnStreetParking:aarhus-handicap-2',
        ]);

        $this->assertSame([$stale], $this->deleted());
        $this->assertSame(2, $result->count);
        $this->assertSame(1, $result->deleted);
    }

    /**
     * Makes sure that the import asks the broker for the entities of the source.
     */
    public function testItAsksTheBrokerForTheEntitiesStampedWithTheSourceId(): void
    {
        $source = new OnStreetParking();

        $this->import($source, $this->features(1));

        $listings = array_values(array_filter($this->requests, static fn (array $request): bool => 'GET' === $request[0]));
        $this->assertCount(1, $listings);

        [, $url, $options] = $listings[0];
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
        $this->assertSame('OnStreetParking', $query['type']);
        $this->assertSame('sourceId=="mtm_spatialmaps-handicap-parking-on-street"', $query['q']);
        $this->assertContains(
            \sprintf('Link: <%s>; rel="http://www.w3.org/ns/json-ld#context"; type="application/ld+json"', $source->definition->contextUrl),
            $options['headers'],
        );
    }

    /**
     * Makes sure that the import reports the entities that the broker rejects.
     */
    public function testItReportsTheEntitiesTheBrokerRejected(): void
    {
        $rejected = 'urn:ngsi-ld:OnStreetParking:aarhus-handicap-2';

        $result = $this->import(new OnStreetParking(), $this->features(1, 2), held: [
            'urn:ngsi-ld:OnStreetParking:aarhus-handicap-1',
            $rejected,
        ], upserted: new MockResponse(json_encode([
            'success' => ['urn:ngsi-ld:OnStreetParking:aarhus-handicap-1'],
            'errors' => [['entityId' => $rejected, 'error' => ['detail' => 'Invalid location']]],
        ], \JSON_THROW_ON_ERROR), ['http_code' => Response::HTTP_MULTI_STATUS]));

        $this->assertSame(Response::HTTP_MULTI_STATUS, $result->status);
        $this->assertEquals([$rejected => new Rejection('Invalid location', ['detail' => 'Invalid location'])], $result->rejected);
        $this->assertSame([], $this->deleted());
    }

    public function testItDeletesNothingWhenTheBrokerHoldsOnlyWhatTheImportYields(): void
    {
        $result = $this->import(new OnStreetParking(), $this->features(1), held: [
            'urn:ngsi-ld:OnStreetParking:aarhus-handicap-1',
        ]);

        $this->assertSame([], $this->deleted());
        $this->assertSame(0, $result->deleted);
    }

    /**
     * Makes sure that an empty import deletes no entities.
     */
    public function testItKeepsWhatTheBrokerHoldsWhenTheImportYieldsNothing(): void
    {
        $result = $this->import(new OnStreetParking(), $this->features(), held: [
            'urn:ngsi-ld:OnStreetParking:aarhus-handicap-1',
        ]);

        $this->assertSame([], $this->requests);
        $this->assertSame(0, $result->deleted);
    }

    /**
     * Makes sure that the import refuses a partial Overpass result.
     */
    public function testItRefusesAPartialOverpassResult(): void
    {
        try {
            $this->import(new Bench(), [
                'elements' => [['type' => 'node', 'id' => 1, 'lat' => 56.15, 'lon' => 10.2, 'tags' => ['amenity' => 'bench']]],
                'remark' => 'runtime error: Query timed out in "query" at line 3 after 181 seconds.',
            ]);
            $this->fail('A partial result was imported.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('timed out', $exception->getMessage());
        }

        $this->assertSame([], $this->requests);
    }

    /**
     * Runs one import against a mock broker.
     *
     * @param array<string, mixed> $data
     * @param list<string>         $held
     */
    private function import(SourceInterface $source, array $data, array $held = [], ?MockResponse $upserted = null): ImportResult
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($held, $upserted): MockResponse {
            $this->requests[] = [$method, $url, $options];

            if (null !== $upserted && str_ends_with($url, '/entityOperations/upsert')) {
                return $upserted;
            }

            if ('GET' === $method) {
                return new MockResponse(json_encode(array_map(
                    static fn (string $id): array => ['id' => $id, 'type' => 'OnStreetParking'],
                    $held,
                ), \JSON_THROW_ON_ERROR));
            }

            return new MockResponse('', ['http_code' => Response::HTTP_NO_CONTENT]);
        }, 'http://broker.example');

        $reader = new readonly class($data) implements SourceReaderInterface {
            /**
             * @param array<string, mixed> $data
             */
            public function __construct(
                private array $data,
            ) {
            }

            public function read(SourceInterface $source): iterable
            {
                return $this->data;
            }
        };

        $importer = new (DataType::Overpass === $source->definition->dataType ? OverpassSourceImporter::class : GetJsonSourceImporter::class)(
            $reader,
            new NgsiLdBroker($client, 'http://broker.example'),
            new Wgs84Transformer(),
            ['https://uri.etsi.org/ngsi-ld/v1/ngsi-ld-core-context.jsonld'],
            new NullLogger(),
        );

        return $importer->import($source);
    }

    /**
     * Gets the entities that the import sends to upsert.
     *
     * @return list<array<string, mixed>>
     */
    private function upserted(): array
    {
        return $this->bodyOf('/entityOperations/upsert');
    }

    /**
     * Gets the ids that the import sends to delete.
     *
     * @return list<string>
     */
    private function deleted(): array
    {
        return $this->bodyOf('/entityOperations/delete');
    }

    /**
     * @return list<mixed>
     */
    private function bodyOf(string $operation): array
    {
        $body = [];
        foreach ($this->requests as [$method, $url, $options]) {
            if ('POST' === $method && str_ends_with($url, $operation)) {
                $body = [...$body, ...json_decode((string) $options['body'], true, flags: \JSON_THROW_ON_ERROR)];
            }
        }

        return $body;
    }

    /**
     * @return array{type: string, features: list<array<string, mixed>>}
     */
    private function features(int ...$keys): array
    {
        return [
            'type' => 'FeatureCollection',
            'features' => array_map(static fn (int $key): array => [
                'type' => 'Feature',
                'geometry' => ['type' => 'Point', 'coordinates' => [575000, 6225000]],
                'properties' => [
                    'mi_prinx' => $key,
                    'vejnavn' => 'Gade',
                    'husnnr' => (string) $key,
                    'invalidepladser' => 2,
                ],
            ], $keys),
        ];
    }
}

/**
 * Source that declares one model and creates entities of a different model.
 */
#[Definition(
    id: 'test:undeclared-model',
    title: 'Test',
    description: '',
    publisher: '',
    contact: '',
    landingPage: '',
    accessUrl: 'https://example.com',
    dataType: DataType::GeoJSON,
    mediaType: 'application/geo+json',
    crs: 'EPSG:4326',
    model: 'OnStreetParking',
    contextUrl: 'https://example.com/context.jsonld',
    updateFrequency: '',
    licence: null,
    omittedFields: [],
    dataset: 'test:undeclared-model',
    datasetTitle: 'Test',
)]
final class UndeclaredModelSource extends AbstractSource
{
    public function createNgsiEntity(array $data, Wgs84Transformer $transformer): NgsiEntity
    {
        return new NgsiEntity('urn:ngsi-ld:ParkingSpot:test', 'ParkingSpot');
    }
}
