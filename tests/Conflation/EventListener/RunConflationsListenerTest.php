<?php

declare(strict_types=1);

namespace App\Tests\Conflation\EventListener;

use App\Broker\NgsiLdBroker;
use App\Conflation\ConflationManager;
use App\Conflation\ConflationRunner;
use App\Conflation\EventListener\RunConflationsListener;
use App\Conflation\Io\ConflationFetcher;
use App\Conflation\Io\MergedDataSetFactory;
use App\Conflation\Io\MergedEntityFactory;
use App\Conflation\Pipeline\ConflationPipeline;
use App\Import\Event\SourcesImportedEvent;
use App\Import\ImportResult;
use App\Merge\PublicToiletConflation;
use App\Source\FindToilet\PublicToilet as FindToiletPublicToilet;
use App\Source\Osm\PublicToilet as OsmPublicToilet;
use App\SourceManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class RunConflationsListenerTest extends TestCase
{
    private const string OSM = 'osm-public-toilet';
    private const string FINDTOILET = 'findtoilet-public-toilet';

    /**
     * The requests the broker received, as "METHOD path".
     *
     * @var list<string>
     */
    private array $requests = [];

    private BufferingLogger $logger;

    /**
     * A listener over the public toilet merge, which reads the OSM and
     * FindToilet toilet data sets, against a broker holding one toilet in
     * each of them.
     */
    private function listener(int $brokerStatus = 204): RunConflationsListener
    {
        $features = [
            self::FINDTOILET => [self::feature('urn:ngsi-ld:PublicToilet:aarhus-toilet-findtoilet-1')],
            self::OSM => [self::feature('urn:ngsi-ld:PublicToilet:aarhus-toilet-osm-node-17')],
        ];

        $client = new MockHttpClient(function (string $method, string $url) use ($brokerStatus, $features): MockResponse {
            $this->requests[] = $method.' '.parse_url($url, \PHP_URL_PATH);

            if ('GET' === $method) {
                parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
                preg_match('/sourceId=="([^"]+)"/', (string) $query['q'], $match);

                return new MockResponse(json_encode(['type' => 'FeatureCollection', 'features' => $features[$match[1]] ?? []], \JSON_THROW_ON_ERROR));
            }

            return new MockResponse('', ['http_code' => $brokerStatus]);
        });

        $broker = new NgsiLdBroker($client, 'http://broker.example');
        $fetcher = new ConflationFetcher(new SourceManager([new OsmPublicToilet(), new FindToiletPublicToilet()]), $broker, new NullLogger());
        $runner = new ConflationRunner($fetcher, new ConflationPipeline(), new MergedDataSetFactory($fetcher), new MergedEntityFactory(), $broker, new NullLogger(), []);

        $this->logger = new BufferingLogger();

        return new RunConflationsListener(new ConflationManager([new PublicToiletConflation()]), $fetcher, $runner, $this->logger);
    }

    /**
     * @return array<string, mixed>
     */
    private static function feature(string $id): array
    {
        return ['id' => $id, 'type' => 'Feature', 'geometry' => ['type' => 'Point', 'coordinates' => [10.21, 56.15]], 'properties' => ['name' => 'Park toilet']];
    }

    /**
     * @param list<string> $imported
     * @param list<string> $failed
     */
    private static function event(array $imported, array $failed = []): SourcesImportedEvent
    {
        return new SourcesImportedEvent(
            array_fill_keys($imported, new ImportResult(1, 0, 201, 'http://broker.example/')),
            array_fill_keys($failed, new \RuntimeException('Feed unreachable')),
        );
    }

    /**
     * The messages logged, as "level: message" with the placeholders filled in.
     *
     * @return list<string>
     */
    private function messages(): array
    {
        $messages = [];
        foreach ($this->logger->cleanLogs() as [$level, $message, $context]) {
            $placeholders = [];
            foreach ($context as $key => $value) {
                if (\is_string($value)) {
                    $placeholders['{'.$key.'}'] = $value;
                }
            }
            $messages[] = $level.': '.strtr($message, $placeholders);
        }

        return $messages;
    }

    public function testItRunsAMergeOnceEveryDataSetItReadsIsImported(): void
    {
        $this->listener()(self::event([self::FINDTOILET, self::OSM]));

        $this->assertContains('POST /ngsi-ld/v1/entityOperations/upsert', $this->requests);
        $this->assertSame([], $this->messages());
    }

    public function testItLeavesAMergeAloneThatReadsNoneOfTheRunsDataSets(): void
    {
        $this->listener()(self::event(['test:osm-public-toilet']));

        $this->assertSame([], $this->requests);
        $this->assertSame([], $this->messages());
    }

    public function testItSkipsAMergeWhenADataSetItReadsFailed(): void
    {
        $this->listener()(self::event([self::OSM], failed: [self::FINDTOILET]));

        $this->assertSame([], $this->requests);
        $this->assertSame(['warning: Skipped merge merged-public-toilets: not every data set it reads was imported (findtoilet-public-toilet).'], $this->messages());
    }

    public function testItSkipsAMergeWhenADataSetItReadsWasNotPartOfTheRun(): void
    {
        $this->listener()(self::event([self::OSM]));

        $this->assertSame([], $this->requests);
        $this->assertSame(['warning: Skipped merge merged-public-toilets: not every data set it reads was imported (findtoilet-public-toilet).'], $this->messages());
    }

    public function testAFailingMergeIsLoggedRatherThanThrown(): void
    {
        $this->listener(brokerStatus: 500)(self::event([self::FINDTOILET, self::OSM]));

        $messages = $this->messages();
        $this->assertCount(1, $messages);
        $this->assertStringStartsWith('error: Merge merged-public-toilets failed: ', $messages[0]);
    }
}
