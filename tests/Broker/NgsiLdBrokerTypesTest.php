<?php

declare(strict_types=1);

namespace App\Tests\Broker;

use App\Broker\NgsiLdBroker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * NgsiLdBroker::types() and NgsiLdBroker::idsOfType(), which find all the
 * entities in the broker without a context.
 */
class NgsiLdBrokerTypesTest extends TestCase
{
    private const string TYPE = 'https://smartdatamodels.org/dataModel.Parking/OnStreetParking';

    public function testItListsTheTypesWithoutAContext(): void
    {
        $requests = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [$method, $url, $options['normalized_headers']['link'] ?? null];

            return new MockResponse(json_encode([
                'id' => 'urn:ngsi-ld:EntityTypeList:1',
                'type' => 'EntityTypeList',
                'typeList' => [self::TYPE, 'https://schema.org/PublicToilet'],
            ], \JSON_THROW_ON_ERROR));
        });

        $types = new NgsiLdBroker($client, 'http://broker.example')->types();

        $this->assertSame([self::TYPE, 'https://schema.org/PublicToilet'], $types);
        $this->assertSame([['GET', 'http://broker.example/ngsi-ld/v1/types', null]], $requests);
    }

    public function testAnEmptyBrokerHasNoTypes(): void
    {
        $client = new MockHttpClient(new MockResponse('{"id": "urn:ngsi-ld:EntityTypeList:1", "type": "EntityTypeList"}'));

        $this->assertSame([], new NgsiLdBroker($client, 'http://broker.example')->types());
    }

    public function testItReadsTheIdsOfATypePageByPage(): void
    {
        $queries = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$queries): MockResponse {
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $queries[] = $query;

            self::assertSame('GET', $method);
            self::assertSame('/ngsi-ld/v1/entities', parse_url($url, \PHP_URL_PATH));
            self::assertArrayNotHasKey('link', $options['normalized_headers']);

            $offset = (int) $query['offset'];
            $count = 0 === $offset ? 1000 : 2;

            return new MockResponse(json_encode(array_map(
                static fn (int $i): array => ['id' => 'urn:ngsi-ld:OnStreetParking:p-'.($offset + $i), 'type' => self::TYPE],
                range(1, $count),
            ), \JSON_THROW_ON_ERROR));
        });

        $ids = new NgsiLdBroker($client, 'http://broker.example')->idsOfType(self::TYPE);

        $this->assertCount(1002, $ids);
        $this->assertSame('urn:ngsi-ld:OnStreetParking:p-1002', $ids[1001]);
        $this->assertSame(['0', '1000'], array_column($queries, 'offset'));
        $this->assertSame([self::TYPE, self::TYPE], array_column($queries, 'type'));
    }

    public function testItFailsIfTheBrokerRejectsTheListOfIds(): void
    {
        $client = new MockHttpClient(new MockResponse('{"title": "Bad request"}', ['http_code' => 400]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('rejected the list of '.self::TYPE.' entities with HTTP 400');

        new NgsiLdBroker($client, 'http://broker.example')->idsOfType(self::TYPE);
    }
}
