<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Broker\NgsiLdBroker;
use App\Conflation\ConflationManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Tests the console output of app:conflation:run.
 *
 * The broker is a mock. It holds two test toilets, 2 m apart, from two data
 * sets that do not agree on the name. Each other data set is empty.
 */
class ConflationRunCommandTest extends KernelTestCase
{
    private const string MERGE = 'test:merged-public-toilets';

    /**
     * The method and the path of each request to the broker.
     *
     * @var list<string>
     */
    private array $requests = [];

    protected function setUp(): void
    {
        self::bootKernel();
        static::getContainer()->set(NgsiLdBroker::class, $this->broker());
    }

    public function testADryRunShowsTheConflictsAndPublishesNothing(): void
    {
        $result = self::runCommand('app:conflation:run', ['merge' => self::MERGE, '--dry-run' => true], verbosity: OutputInterface::VERBOSITY_VERBOSE, normalizers: [self::oneLine(...)]);

        $this->assertCommandIsSuccessful($result);
        $display = $result->getDisplay();
        $this->assertStringContainsString('Read 2 records from', $display);
        $this->assertStringContainsString('Merged them into 1 entities, 1 of them with conflicts.', $display);
        $this->assertStringContainsString('name = "Parktoilet" (test:findtoilet-public-toilet) / "Park toilet" (test:osm-public-toilet)', $display);
        $this->assertStringContainsString('Dry run: the command published nothing.', $display);
        $this->assertNotContains('POST /ngsi-ld/v1/entityOperations/upsert', $this->requests);
    }

    public function testItPublishesTheMergedDataSet(): void
    {
        $result = self::runCommand('app:conflation:run', ['merge' => self::MERGE], normalizers: [self::oneLine(...)]);

        $this->assertCommandIsSuccessful($result);
        $this->assertStringContainsString('Published 1 entities as test:merged-public-toilets and deleted 0 stale ones.', $result->getDisplay());
        $this->assertContains('POST /ngsi-ld/v1/entityOperations/upsert', $this->requests);
    }

    public function testItRunsEachMergeIfTheArgumentIsLeftOut(): void
    {
        $result = self::runCommand('app:conflation:run', ['--dry-run' => true]);

        $this->assertCommandIsSuccessful($result);
        foreach (array_keys(static::getContainer()->get(ConflationManager::class)->getConflations()) as $id) {
            $this->assertStringContainsString($id, $result->getDisplay());
        }
    }

    /**
     * A scheduled run must be able to see that a merge failed, so the exit
     * code tells it. The other merges still run.
     */
    public function testItRunsTheOtherMergesAfterAFailedMerge(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $client = new MockHttpClient(static fn (string $method, string $url): MockResponse => str_contains(urldecode($url), 'sourceId=="test:osm-bench"')
            ? new MockResponse('', ['http_code' => 500])
            : new MockResponse(json_encode(['type' => 'FeatureCollection', 'features' => []], \JSON_THROW_ON_ERROR)));
        static::getContainer()->set(NgsiLdBroker::class, new NgsiLdBroker($client, 'http://broker.example'));

        $result = self::runCommand('app:conflation:run', ['--dry-run' => true], normalizers: [self::oneLine(...)]);

        $this->assertCommandFailed($result);
        $display = $result->getDisplay();
        $this->assertStringContainsString('The broker rejected the read of test:osm-bench with HTTP 500', $display);
        $this->assertStringContainsString(self::MERGE, $display);
        $this->assertStringContainsString('Dry run: the command published nothing.', $display);
    }

    public function testItWarnsIfNoMergeIsDeclared(): void
    {
        static::getContainer()->set(ConflationManager::class, new ConflationManager([]));

        $result = self::runCommand('app:conflation:run');

        $this->assertCommandIsSuccessful($result);
        $this->assertStringContainsString('No merge is declared.', $result->getDisplay());
    }

    /**
     * Gets a broker that serves the two toilets and accepts each write.
     */
    private function broker(): NgsiLdBroker
    {
        $features = [
            'test:findtoilet-public-toilet' => [self::feature('urn:ngsi-ld:PublicToilet:test:aarhus-toilet-findtoilet-1', 10.21, 'Parktoilet')],
            'test:osm-public-toilet' => [self::feature('urn:ngsi-ld:PublicToilet:test:aarhus-toilet-osm-node-17', 10.21003, 'Park toilet')],
        ];

        $client = new MockHttpClient(function (string $method, string $url) use ($features): MockResponse {
            $this->requests[] = $method.' '.parse_url($url, \PHP_URL_PATH);

            if ('GET' === $method) {
                parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
                preg_match('/sourceId=="([^"]+)"/', (string) $query['q'], $match);

                return new MockResponse(json_encode(['type' => 'FeatureCollection', 'features' => $features[$match[1]] ?? []], \JSON_THROW_ON_ERROR));
            }

            return new MockResponse('', ['http_code' => 204]);
        });

        return new NgsiLdBroker($client, 'http://broker.example');
    }

    /**
     * @return array<string, mixed>
     */
    private static function feature(string $id, float $longitude, string $name): array
    {
        return ['id' => $id, 'type' => 'Feature', 'geometry' => ['type' => 'Point', 'coordinates' => [$longitude, 56.15]], 'properties' => ['name' => $name]];
    }

    /**
     * Puts the output on one line, because the console breaks a long message into lines.
     */
    private static function oneLine(string $display): string
    {
        return (string) preg_replace('/\s+/', ' ', $display);
    }
}
