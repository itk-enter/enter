<?php

declare(strict_types=1);

namespace App\Tests\Broker;

use App\Broker\NgsiLdBroker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * NgsiLdBroker::count(), which counts the entities of a data set.
 */
class NgsiLdBrokerCountTest extends TestCase
{
    private const string CONTEXT = 'https://context.example/toilet.jsonld';

    public function testItReadsTheCountFromTheHeader(): void
    {
        $requests = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $requests[] = [$method, parse_url($url, \PHP_URL_PATH), $query, $options['normalized_headers']['link']];

            return new MockResponse('[{"id": "urn:ngsi-ld:PublicToilet:t-1", "type": "PublicToilet"}]', [
                'response_headers' => ['NGSILD-Results-Count' => '42'],
            ]);
        });

        $count = new NgsiLdBroker($client, 'http://broker.example')->count('test:osm-public-toilet', self::CONTEXT);

        $this->assertSame(42, $count);
        $this->assertSame([[
            'GET',
            '/ngsi-ld/v1/entities',
            // The limit is 1, because Scorpio counts 0 if the limit is 0.
            ['q' => 'sourceId=="test:osm-public-toilet"', 'count' => 'true', 'limit' => '1'],
            ['Link: <'.self::CONTEXT.'>; rel="http://www.w3.org/ns/json-ld#context"; type="application/ld+json"'],
        ]], $requests);
    }

    public function testADataSetWithoutEntitiesCountsZero(): void
    {
        $client = new MockHttpClient(new MockResponse('[]', ['response_headers' => ['NGSILD-Results-Count' => '0']]));

        $this->assertSame(0, new NgsiLdBroker($client, 'http://broker.example')->count('test:osm-public-toilet', self::CONTEXT));
    }

    public function testItFailsIfTheBrokerGivesNoCount(): void
    {
        $client = new MockHttpClient(new MockResponse('[]'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('gave no count of test:osm-public-toilet');

        new NgsiLdBroker($client, 'http://broker.example')->count('test:osm-public-toilet', self::CONTEXT);
    }

    public function testItFailsIfTheBrokerRejectsTheCount(): void
    {
        $client = new MockHttpClient(new MockResponse('{"title": "Bad request"}', ['http_code' => 400]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('rejected the count of test:osm-public-toilet with HTTP 400');

        new NgsiLdBroker($client, 'http://broker.example')->count('test:osm-public-toilet', self::CONTEXT);
    }
}
