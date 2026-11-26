<?php

declare(strict_types=1);

namespace App\Tests\Broker;

use App\Broker\NgsiLdBroker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * NgsiLdBroker::counts(), which counts the entities of several data sets at
 * the same time.
 */
class NgsiLdBrokerCountsTest extends TestCase
{
    private const string CONTEXT = 'https://context.example/toilet.jsonld';

    public function testItCountsTheEntitiesOfTheTypeOfEachDataSet(): void
    {
        $queries = [];
        $client = new MockHttpClient(static function (string $method, string $url) use (&$queries): MockResponse {
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $queries[] = $query;

            return new MockResponse('[]', [
                'response_headers' => ['NGSILD-Results-Count' => 'sourceId=="test:a"' === $query['q'] ? '3' : '0'],
            ]);
        });

        $counts = new NgsiLdBroker($client, 'http://broker.example')->counts([
            'test:a' => ['contextUrl' => self::CONTEXT, 'type' => 'PublicToilet'],
            'test:b' => ['contextUrl' => self::CONTEXT, 'type' => 'Bench'],
        ]);

        $this->assertSame(['test:a' => 3, 'test:b' => 0], $counts);
        $this->assertSame(['PublicToilet', 'Bench'], array_column($queries, 'type'));
    }

    public function testADataSetThatTheBrokerCannotCountHasNoCount(): void
    {
        $client = new MockHttpClient([
            new MockResponse('{"title": "Bad request"}', ['http_code' => 400]),
            new MockResponse('[]'),
            new MockResponse('', ['error' => 'The broker is not available']),
            new MockResponse('[]', ['response_headers' => ['NGSILD-Results-Count' => '2']]),
        ]);

        $counts = new NgsiLdBroker($client, 'http://broker.example')->counts([
            'test:rejected' => ['contextUrl' => self::CONTEXT, 'type' => null],
            'test:no-header' => ['contextUrl' => self::CONTEXT, 'type' => null],
            'test:no-broker' => ['contextUrl' => self::CONTEXT, 'type' => null],
            'test:counted' => ['contextUrl' => self::CONTEXT, 'type' => null],
        ]);

        $this->assertSame(['test:rejected' => null, 'test:no-header' => null, 'test:no-broker' => null, 'test:counted' => 2], $counts);
    }
}
