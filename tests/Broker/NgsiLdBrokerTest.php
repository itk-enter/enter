<?php

declare(strict_types=1);

namespace App\Tests\Broker;

use App\Broker\NgsiLdBroker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class NgsiLdBrokerTest extends TestCase
{
    /**
     * The context of the toilet data sets, as their sources declare it.
     */
    private const string CONTEXT = 'https://raw.githubusercontent.com/itk-enter/data-models/PublicToilet/v0.0.2/dataModel.PointOfInterest/context.jsonld';

    /**
     * @param list<string> $ids
     */
    private static function page(array $ids, ?string $next = null): MockResponse
    {
        $features = array_map(static fn (string $id): array => [
            'id' => $id,
            'type' => 'Feature',
            'geometry' => ['type' => 'Point', 'coordinates' => [10.21, 56.15]],
            'properties' => ['type' => 'PublicToilet', 'name' => 'Toilet '.$id],
        ], $ids);

        return new MockResponse(
            json_encode(['type' => 'FeatureCollection', 'features' => $features], \JSON_THROW_ON_ERROR),
            ['response_headers' => null === $next ? [] : ['Link' => \sprintf('<%s>; rel="next"', $next)]],
        );
    }

    public function testItReadsADataSetsEntitiesPageByPage(): void
    {
        $requests = [];
        $pages = [
            self::page(['A-1', 'A-2'], 'http://scorpio:9090/ngsi-ld/v1/entities?q=sourceId%3D%3D%22osm-public-toilet%22&format=simplified&limit=1000&offset=1000'),
            self::page(['A-3']),
        ];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests, &$pages): MockResponse {
            $requests[] = ['method' => $method, 'url' => $url, 'headers' => $options['normalized_headers']];

            return array_shift($pages);
        });

        $features = iterator_to_array(new NgsiLdBroker($client, 'http://broker.example/')->features('osm-public-toilet', self::CONTEXT), false);

        $this->assertSame(['A-1', 'A-2', 'A-3'], array_column($features, 'id'));
        $this->assertSame(['type' => 'PublicToilet', 'name' => 'Toilet A-1'], $features[0]['properties']);

        $this->assertCount(2, $requests);
        $this->assertSame('GET', $requests[0]['method']);
        $this->assertSame('http://broker.example/ngsi-ld/v1/entities?q=sourceId%3D%3D%22osm-public-toilet%22&format=simplified&limit=1000', $requests[0]['url']);
        $this->assertSame(['Accept: application/geo+json'], $requests[0]['headers']['accept']);
        $this->assertSame(['Link: <'.self::CONTEXT.'>; rel="http://www.w3.org/ns/json-ld#context"; type="application/ld+json"'], $requests[0]['headers']['link']);
        // The next page is asked for at the broker's address as configured
        // here, not the one the broker links with.
        $this->assertSame('http://broker.example/ngsi-ld/v1/entities?q=sourceId%3D%3D%22osm-public-toilet%22&format=simplified&limit=1000&offset=1000', $requests[1]['url']);
    }

    public function testADataSetWithoutEntitiesReadsAsNone(): void
    {
        $client = new MockHttpClient([new MockResponse('[]')]);

        $this->assertSame([], iterator_to_array(new NgsiLdBroker($client, 'http://broker.example')->features('osm-public-toilet', self::CONTEXT)));
    }

    public function testItDeletesEntitiesInOneBatch(): void
    {
        $sent = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent[] = [$method, $url, json_decode((string) $options['body'], true)];

            return new MockResponse('', ['http_code' => 204]);
        });

        $broker = new NgsiLdBroker($client, 'http://broker.example');

        $this->assertSame(204, $broker->delete([]));
        $this->assertSame([], $sent);

        $this->assertSame(204, $broker->delete(['urn:a', 'urn:b']));
        $this->assertSame([['POST', 'http://broker.example/ngsi-ld/v1/entityOperations/delete', ['urn:a', 'urn:b']]], $sent);
    }

    public function testItFailsWhenTheBrokerRejectsTheRead(): void
    {
        $client = new MockHttpClient([new MockResponse('{"title":"Bad request"}', ['http_code' => 400])]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP 400');

        iterator_to_array(new NgsiLdBroker($client, 'http://broker.example')->features('osm-public-toilet', self::CONTEXT));
    }
}
