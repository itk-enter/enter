<?php

declare(strict_types=1);

namespace App\Tests\SourceImporter;

use App\Broker\NgsiLdBroker;
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

/**
 * What the import adds to every entity on its way to the broker, whichever
 * source it came from, and what it removes once there.
 */
class SourceImporterTest extends TestCase
{
    /**
     * Every request the broker was sent, as method, URL and options.
     *
     * @var list<array{string, string, array<string, mixed>}>
     */
    private array $requests = [];

    /**
     * Sources publish into shared models, so the broker cannot tell their
     * entities apart by type. The stamp is what a reader filters on, and it
     * is the source's id rather than its access URL because the id is the
     * one thing about a source that must not change.
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
     * The catalogue says which model a source publishes, and the map, the
     * list of sources and any reader trust it. A mapping that strays from
     * it is a bug to fix, not data to publish.
     */
    public function testItRefusesAnEntityOfAModelTheSourceDoesNotDeclare(): void
    {
        $source = new UndeclaredModelSource();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('ParkingSpot');

        $this->import($source, $this->features(1));
    }

    /**
     * A record gone from the feed, or sorted into another source's model,
     * leaves its entity behind under this source unless the import removes
     * it; in a split feed that publishes the record twice.
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
     * The broker can only tell a source's entities apart by the stamp, and
     * only finds the model by its short name with the source's context.
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
     * An entity the broker rejected was still yielded, so the import keeps
     * what the broker held for it and reports the rejection.
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
        ], \JSON_THROW_ON_ERROR), ['http_code' => 207]));

        $this->assertSame(207, $result->status);
        $this->assertSame([$rejected => 'Invalid location'], $result->rejected);
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
     * An empty feed is far likelier an outage than every record gone, so it
     * must not wipe the source from the broker.
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
     * Overpass answers a query that ran out of time with 200 and what it had
     * found so far. Imported, the part would sweep away the rest.
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
     * Runs one import against a broker that records what it is sent and
     * holds the given entity ids for the source.
     *
     * @param array<string, mixed> $data     the feed as the reader returns it
     * @param list<string>         $held     ids the broker answers a listing with
     * @param MockResponse|null    $upserted what the broker answers the upsert with, if not 204
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

            return new MockResponse('', ['http_code' => 204]);
        });

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
     * The entities the broker was sent to upsert.
     *
     * @return list<array<string, mixed>>
     */
    private function upserted(): array
    {
        return $this->bodyOf('/entityOperations/upsert');
    }

    /**
     * The ids the broker was sent to delete.
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
 * Declares one model and maps onto another.
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
)]
final class UndeclaredModelSource extends AbstractSource
{
    public function createNgsiEntity(array $data, Wgs84Transformer $transformer): NgsiEntity
    {
        return new NgsiEntity('urn:ngsi-ld:ParkingSpot:test', 'ParkingSpot');
    }
}
