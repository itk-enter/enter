<?php

declare(strict_types=1);

namespace App\Tests\SourceImporter;

use App\Broker\NgsiLdBroker;
use App\Geo\Wgs84Transformer;
use App\Import\Exception\SweepLimitException;
use App\Import\ImportResult;
use App\Source\MtmSpatialMaps\OnStreetParking;
use App\Source\SourceInterface;
use App\SourceImporter\GetJsonSourceImporter;
use App\SourceReader\FeedResponse;
use App\SourceReader\SourceReaderInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests that an import refuses to delete too many of the entities of a
 * source, and that it changes nothing then.
 */
class SweepLimitTest extends TestCase
{
    /**
     * @var list<string> the requests to the broker, as "METHOD path"
     */
    private array $requests = [];

    public function testItRefusesAnImportThatWouldDeleteMoreThanTheLimit(): void
    {
        try {
            $this->import(feed: range(1, 10), held: range(1, 30));
            $this->fail('The import deleted 20 of 30 entities.');
        } catch (SweepLimitException $exception) {
            $this->assertSame(20, $exception->stale);
            $this->assertSame(30, $exception->held);
            $this->assertStringContainsString('would delete 20 of the 30 entities', $exception->getMessage());
        }

        $this->assertSame(['GET /ngsi-ld/v1/entities'], $this->requests);
    }

    public function testItDeletesUpToTheFloorAlsoAboveTheLimit(): void
    {
        $result = $this->import(feed: range(1, 5), held: range(1, 15));

        $this->assertSame(10, $result->deleted);
    }

    public function testItDeletesMoreThanTheFloorWithinTheLimit(): void
    {
        $result = $this->import(feed: range(1, 85), held: range(1, 100));

        $this->assertSame(15, $result->deleted);
    }

    public function testALimitOfOneAcceptsAnyDeletion(): void
    {
        $result = $this->import(feed: [1], held: range(1, 30), sweepLimit: 1.0);

        $this->assertSame(29, $result->deleted);
    }

    /**
     * Runs one import against a mock broker that holds some entities of the
     * source.
     *
     * @param list<int> $feed the keys of the records in the feed
     * @param list<int> $held the keys of the entities in the broker
     */
    private function import(array $feed, array $held, float $sweepLimit = 0.2): ImportResult
    {
        $client = new MockHttpClient(function (string $method, string $url) use ($held): MockResponse {
            $this->requests[] = $method.' '.parse_url($url, \PHP_URL_PATH);

            return Request::METHOD_GET === $method
                ? new MockResponse(json_encode(array_map(
                    static fn (int $key): array => ['id' => 'urn:ngsi-ld:OnStreetParking:aarhus-handicap-'.$key, 'type' => 'OnStreetParking'],
                    $held,
                ), \JSON_THROW_ON_ERROR))
                : new MockResponse('', ['http_code' => Response::HTTP_NO_CONTENT]);
        }, 'http://broker.example');

        $reader = new readonly class($feed) implements SourceReaderInterface {
            /**
             * @param list<int> $keys
             */
            public function __construct(
                private array $keys,
            ) {
            }

            public function read(SourceInterface $source): FeedResponse
            {
                return new FeedResponse(json_encode([
                    'type' => 'FeatureCollection',
                    'features' => array_map(static fn (int $key): array => [
                        'type' => 'Feature',
                        'geometry' => ['type' => 'Point', 'coordinates' => [575000, 6225000]],
                        'properties' => ['mi_prinx' => $key, 'vejnavn' => 'Gade', 'husnnr' => (string) $key, 'invalidepladser' => 2],
                    ], $this->keys),
                ], \JSON_THROW_ON_ERROR), 'application/json');
            }
        };

        $importer = new GetJsonSourceImporter(
            $reader,
            new NgsiLdBroker($client, 'http://broker.example'),
            new Wgs84Transformer(),
            ['https://uri.etsi.org/ngsi-ld/v1/ngsi-ld-core-context.jsonld'],
            new NullLogger(),
            $sweepLimit,
        );

        return $importer->import(new OnStreetParking());
    }
}
