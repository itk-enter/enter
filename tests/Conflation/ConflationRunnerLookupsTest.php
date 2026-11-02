<?php

declare(strict_types=1);

namespace App\Tests\Conflation;

use App\Broker\NgsiLdBroker;
use App\Conflation\Conflation;
use App\Conflation\ConflationRunner;
use App\Conflation\Io\ConflationFetcher;
use App\Conflation\Io\MergedDataSetFactory;
use App\Conflation\Io\MergedEntityFactory;
use App\Conflation\Lookup\LookupMessage;
use App\Conflation\Lookup\RemoteLookups;
use App\Conflation\MergeByLocation\OneToOneClosestFirst;
use App\Conflation\Pipeline\ConflationPipeline;
use App\Source\Osm\PublicToilet as OsmPublicToilet;
use App\SourceManager;
use App\Tests\Conflation\Fixtures\AerialPhotos;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A run of a merge with a lookup: the run marks the checks in the entities
 * that it publishes, and sends the messages after it publishes.
 */
class ConflationRunnerLookupsTest extends TestCase
{
    public function testTheRunPublishesTheMarkAndThenSendsTheMessage(): void
    {
        /** @var list<string> $events */
        $events = [];
        $upserted = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$events, &$upserted): MockResponse {
            $path = (string) parse_url($url, \PHP_URL_PATH);
            $events[] = $method.' '.$path;
            if (str_ends_with($path, '/upsert')) {
                $upserted = json_decode((string) $options['body'], true);
            }
            if ('GET' !== $method) {
                return new MockResponse('', ['http_code' => 204]);
            }
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

            // The normalized read of the merged data set finds nothing, so the
            // entity is new. The OSM data set holds one toilet.
            if (isset($query['attrs'])) {
                return new MockResponse('[]');
            }

            return new MockResponse(json_encode(['type' => 'FeatureCollection', 'features' => str_contains((string) $query['q'], 'osm-public-toilet') ? [[
                'id' => 'urn:ngsi-ld:PublicToilet:aarhus-toilet-osm-node-17',
                'type' => 'Feature',
                'geometry' => ['type' => 'Point', 'coordinates' => [10.21, 56.15]],
                'properties' => ['name' => 'Park toilet'],
            ]] : []], \JSON_THROW_ON_ERROR));
        });
        $bus = new readonly class(static function (object $message) use (&$events): void {
            $events[] = 'dispatch '.$message::class;
        }) implements MessageBusInterface {
            public function __construct(private \Closure $record)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                ($this->record)($message);

                return new Envelope($message, $stamps);
            }
        };

        $broker = new NgsiLdBroker($client, 'http://broker.example');
        $fetcher = new ConflationFetcher(new SourceManager([new OsmPublicToilet()]), $broker, new NullLogger());
        $runner = new ConflationRunner($fetcher, new ConflationPipeline(), new MergedDataSetFactory($fetcher), new MergedEntityFactory(), $broker, new RemoteLookups($broker, $bus, new MockClock('2026-11-20T12:00:00Z')), new NullLogger(), []);

        $runner->run(new Conflation(
            id: 'merged-public-toilets',
            title: 'Merged toilets',
            description: 'Toilets merged from several data sets.',
            resultModel: 'PublicToilet',
            defining: ['PublicToilet'],
            mergeByLocation: new OneToOneClosestFirst(radius: 10.0),
            lookups: [AerialPhotos::class => ['image']],
        ));

        $this->assertSame([
            'GET /ngsi-ld/v1/entities',
            'GET /ngsi-ld/v1/entities',
            'POST /ngsi-ld/v1/entityOperations/upsert',
            // The read of the published entities, to find the stale ones.
            // There are none, so there is no delete.
            'GET /ngsi-ld/v1/entities',
            'dispatch '.LookupMessage::class,
        ], $events);
        $this->assertSame([
            'type' => 'Property',
            'value' => 'pending',
            'requestedAt' => ['type' => 'Property', 'value' => '2026-11-20T12:00:00Z'],
        ], $upserted[0]['lookupAerialPhotos'] ?? null);
    }
}
