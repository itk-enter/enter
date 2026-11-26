<?php

declare(strict_types=1);

namespace App\Tests\Broker;

use App\Broker\NgsiLdBroker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * NgsiLdBroker::entities(), which reads attributes in normalized form.
 */
class NgsiLdBrokerEntitiesTest extends TestCase
{
    /**
     * The base URI of the broker client. The real client is a scoped client
     * with the same base URI.
     */
    private const string BASE_URI = 'http://broker.example';

    public function testItReadsEachPageUntilAPageIsNotFull(): void
    {
        $offsets = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$offsets): MockResponse {
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $offsets[] = (int) $query['offset'];

            self::assertSame('GET', $method);
            self::assertSame('/ngsi-ld/v1/entities', parse_url($url, \PHP_URL_PATH));
            self::assertSame('sourceId=="merged-toilets"', $query['q']);
            self::assertSame('location,image', $query['attrs']);
            self::assertContains('Accept: application/json', $options['headers']);

            $count = 0 === (int) $query['offset'] ? 1000 : 1;
            $offset = (int) $query['offset'];

            return new MockResponse(json_encode(array_map(
                static fn (int $i): array => ['id' => 'urn:ngsi-ld:PublicToilet:t-'.($offset + $i), 'type' => 'PublicToilet'],
                range(1, $count),
            ), \JSON_THROW_ON_ERROR));
        }, self::BASE_URI);

        $entities = iterator_to_array(new NgsiLdBroker($client, self::BASE_URI)->entities('merged-toilets', 'https://context.example/toilet.jsonld', ['location', 'image']));

        $this->assertSame([0, 1000], $offsets);
        $this->assertCount(1001, $entities);
        $this->assertSame('PublicToilet', $entities['urn:ngsi-ld:PublicToilet:t-1001']['type']);
    }

    public function testItFailsIfTheBrokerRejectsTheRead(): void
    {
        $client = new MockHttpClient(new MockResponse('{"title": "Bad request"}', ['http_code' => 400]), self::BASE_URI);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('rejected the read of merged-toilets with HTTP 400');

        iterator_to_array(new NgsiLdBroker($client, self::BASE_URI)->entities('merged-toilets', 'https://context.example/toilet.jsonld', ['location']));
    }
}
