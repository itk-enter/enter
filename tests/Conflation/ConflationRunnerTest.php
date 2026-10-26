<?php

declare(strict_types=1);

namespace App\Tests\Conflation;

use App\Broker\NgsiLdBroker;
use App\Conflation\Conflation;
use App\Conflation\ConflationRunner;
use App\Conflation\Io\ConflationFetcher;
use App\Conflation\Io\MergedDataSetFactory;
use App\Conflation\Io\MergedEntityFactory;
use App\Conflation\MergeByLocation\OneToOneClosestFirst;
use App\Conflation\Pipeline\ConflationPipeline;
use App\Source\FindToilet\PublicToilet as FindToiletPublicToilet;
use App\Source\Osm\PublicToilet as OsmPublicToilet;
use App\SourceManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class ConflationRunnerTest extends TestCase
{
    private const string MERGED = 'urn:ngsi-ld:PublicToilet:merged-public-toilets-aarhus-toilet-findtoilet-1';
    private const string STALE = 'urn:ngsi-ld:PublicToilet:merged-public-toilets-aarhus-toilet-findtoilet-9';

    /**
     * The requests the broker received, as "METHOD path" with the body sent.
     *
     * @var list<array{string, mixed}>
     */
    private array $requests = [];

    /**
     * A runner over the OSM and FindToilet toilet data sets, each stating one
     * toilet 2 m from the other, and a merged data set holding one toilet
     * from an earlier run that this run no longer produces.
     */
    private function runner(): ConflationRunner
    {
        $features = [
            'findtoilet-public-toilet' => [self::feature('urn:ngsi-ld:PublicToilet:aarhus-toilet-findtoilet-1', 10.21, ['name' => 'Parktoilet'])],
            'osm-public-toilet' => [self::feature('urn:ngsi-ld:PublicToilet:aarhus-toilet-osm-node-17', 10.21003, ['name' => 'Park toilet', 'fee' => false])],
            'merged-public-toilets' => [self::feature(self::MERGED, 10.21, []), self::feature(self::STALE, 10.3, [])],
        ];

        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($features): MockResponse {
            $path = (string) parse_url($url, \PHP_URL_PATH);
            $this->requests[] = [$method.' '.$path, isset($options['body']) ? json_decode((string) $options['body'], true) : null];

            if ('GET' === $method) {
                parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
                preg_match('/sourceId=="([^"]+)"/', (string) $query['q'], $match);

                return new MockResponse(json_encode(['type' => 'FeatureCollection', 'features' => $features[$match[1]] ?? []], \JSON_THROW_ON_ERROR));
            }

            return new MockResponse('', ['http_code' => 204]);
        });

        $broker = new NgsiLdBroker($client, 'http://broker.example');
        $fetcher = new ConflationFetcher(new SourceManager([new OsmPublicToilet(), new FindToiletPublicToilet()]), $broker, new NullLogger());

        return new ConflationRunner(
            $fetcher,
            new ConflationPipeline(),
            new MergedDataSetFactory($fetcher),
            new MergedEntityFactory(),
            $broker,
            new NullLogger(),
            ['https://uri.etsi.org/ngsi-ld/v1/ngsi-ld-core-context.jsonld'],
        );
    }

    /**
     * @param array<string, mixed> $properties
     *
     * @return array<string, mixed>
     */
    private static function feature(string $id, float $longitude, array $properties): array
    {
        return ['id' => $id, 'type' => 'Feature', 'geometry' => ['type' => 'Point', 'coordinates' => [$longitude, 56.15]], 'properties' => $properties];
    }

    private static function conflation(): Conflation
    {
        return new Conflation(
            id: 'merged-public-toilets',
            title: 'Merged toilets',
            description: 'Toilets merged from several data sets.',
            resultModel: 'PublicToilet',
            defining: ['PublicToilet'],
            mergeByLocation: new OneToOneClosestFirst(radius: 10.0),
        );
    }

    public function testItPublishesTheMergedDataSetAndDeletesWhatTheRunNoLongerProduces(): void
    {
        $run = $this->runner()->run(self::conflation());

        $this->assertSame(2, $run->records);
        $this->assertCount(1, $run->entities);
        $this->assertSame(1, $run->upserted);
        $this->assertSame(1, $run->deleted);

        $requests = array_column($this->requests, 0);
        $this->assertSame([
            'GET /ngsi-ld/v1/entities',
            'GET /ngsi-ld/v1/entities',
            'POST /ngsi-ld/v1/entityOperations/upsert',
            'GET /ngsi-ld/v1/entities',
            'POST /ngsi-ld/v1/entityOperations/delete',
        ], $requests);

        [, $upserted] = $this->requests[2];
        $this->assertSame(self::MERGED, $upserted[0]['id']);
        // The data sets disagree on the name, and the merge names no approach.
        $this->assertSame(['type' => 'Property', 'value' => false], $upserted[0]['fee']);
        $this->assertArrayNotHasKey('name', $upserted[0]);
        $this->assertSame(
            ['https://raw.githubusercontent.com/itk-enter/data-models/PublicToilet/v0.0.2/dataModel.PointOfInterest/context.jsonld', 'https://uri.etsi.org/ngsi-ld/v1/ngsi-ld-core-context.jsonld'],
            $upserted[0]['@context'],
        );

        [, $deleted] = $this->requests[4];
        $this->assertSame([self::STALE], $deleted);
    }

    public function testADryRunPublishesNothing(): void
    {
        $run = $this->runner()->run(self::conflation(), publish: false);

        $this->assertCount(1, $run->entities);
        $this->assertCount(1, $run->withConflicts());
        $this->assertNull($run->upserted);
        $this->assertSame(['GET /ngsi-ld/v1/entities', 'GET /ngsi-ld/v1/entities'], array_column($this->requests, 0));
    }
}
