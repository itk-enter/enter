<?php

declare(strict_types=1);

namespace App\Tests\Conflation;

use App\Broker\NgsiLdBroker;
use App\Conflation\Conflation;
use App\Conflation\ConflationRun;
use App\Conflation\ConflationRunner;
use App\Conflation\Io\ConflationFetcher;
use App\Conflation\Io\MergedDataSetFactory;
use App\Conflation\Io\MergedEntityFactory;
use App\Conflation\Lookup\RemoteLookups;
use App\Conflation\MergeByLocation\OneToOneClosestFirst;
use App\Conflation\Pipeline\ConflationPipeline;
use App\Source\FindToilet\PublicToilet as FindToiletPublicToilet;
use App\Source\Osm\PublicToilet as OsmPublicToilet;
use App\SourceManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\MessageBus;

/**
 * Tests that a failed merge does not stop the other merges. Two merges read
 * the same toilets, and the broker refuses the first upsert.
 */
class ConflationRunnerRunAllTest extends TestCase
{
    private BufferingLogger $logger;

    private int $upserts = 0;

    protected function setUp(): void
    {
        $this->logger = new BufferingLogger();
    }

    protected function tearDown(): void
    {
        $this->logger->cleanLogs();
    }

    public function testAFailedMergeDoesNotStopTheNextMerge(): void
    {
        $results = [];
        $failed = $this->runner()->runAll(
            [self::conflation('merged-first'), self::conflation('merged-second')],
            onEach: static function (Conflation $conflation, ConflationRun|\Throwable $result) use (&$results): void {
                $results[$conflation->id] = $result::class;
            },
        );

        $this->assertSame(['merged-first'], array_keys($failed));
        $this->assertSame(['merged-first' => \RuntimeException::class, 'merged-second' => ConflationRun::class], $results);
        $this->assertSame(2, $this->upserts);
    }

    public function testItLogsAFailedMerge(): void
    {
        $this->runner()->runAll([self::conflation('merged-first')]);

        $logs = $this->logger->cleanLogs();
        $this->assertCount(1, $logs);
        $this->assertSame('error', $logs[0][0]);
        $this->assertSame('merged-first', $logs[0][2]['merge']);
    }

    /**
     * A runner over the OSM and FindToilet toilet data sets, each with one
     * toilet. The broker refuses the first upsert and accepts the others.
     */
    private function runner(): ConflationRunner
    {
        $features = [
            'findtoilet-public-toilet' => [self::feature('urn:ngsi-ld:PublicToilet:aarhus-toilet-findtoilet-1', 10.21)],
            'osm-public-toilet' => [self::feature('urn:ngsi-ld:PublicToilet:aarhus-toilet-osm-node-17', 10.21003)],
        ];

        $client = new MockHttpClient(function (string $method, string $url) use ($features): MockResponse {
            if ('GET' === $method) {
                parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
                preg_match('/sourceId=="([^"]+)"/', (string) $query['q'], $match);

                return new MockResponse(json_encode(['type' => 'FeatureCollection', 'features' => $features[$match[1]] ?? []], \JSON_THROW_ON_ERROR));
            }

            if (str_ends_with((string) parse_url($url, \PHP_URL_PATH), '/upsert') && 1 === ++$this->upserts) {
                return new MockResponse('', ['http_code' => 500]);
            }

            return new MockResponse('', ['http_code' => 204]);
        });

        $broker = new NgsiLdBroker($client, 'http://broker.example');
        $fetcher = new ConflationFetcher(new SourceManager([new OsmPublicToilet(), new FindToiletPublicToilet()]), $broker, $this->logger);

        return new ConflationRunner(
            $fetcher,
            new ConflationPipeline(),
            new MergedDataSetFactory($fetcher),
            new MergedEntityFactory(),
            $broker,
            new RemoteLookups($broker, new MessageBus(), new MockClock()),
            $this->logger,
            ['https://uri.etsi.org/ngsi-ld/v1/ngsi-ld-core-context.jsonld'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function feature(string $id, float $longitude): array
    {
        return ['id' => $id, 'type' => 'Feature', 'geometry' => ['type' => 'Point', 'coordinates' => [$longitude, 56.15]], 'properties' => []];
    }

    private static function conflation(string $id): Conflation
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
}
