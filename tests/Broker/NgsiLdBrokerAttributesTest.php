<?php

declare(strict_types=1);

namespace App\Tests\Broker;

use App\Broker\NgsiLdBroker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * NgsiLdBroker::entity() and NgsiLdBroker::appendAttributes(), which read and
 * write some attributes of one entity.
 */
class NgsiLdBrokerAttributesTest extends TestCase
{
    private const string ID = 'urn:ngsi-ld:PublicToilet:t-1';

    private const string CONTEXT = 'https://context.example/toilet.jsonld';

    public function testItReadsSomeAttributesOfOneEntity(): void
    {
        $client = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            self::assertSame('GET', $method);
            self::assertSame('/ngsi-ld/v1/entities/'.rawurlencode(self::ID), parse_url($url, \PHP_URL_PATH));
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            self::assertSame('location,lookupAerialPhotos', $query['attrs']);

            return new MockResponse('{"id": "urn:ngsi-ld:PublicToilet:t-1", "type": "PublicToilet"}');
        });

        $entity = new NgsiLdBroker($client, 'http://broker.example')->entity(self::ID, self::CONTEXT, ['location', 'lookupAerialPhotos']);

        $this->assertSame(['id' => self::ID, 'type' => 'PublicToilet'], $entity);
    }

    public function testAnEntityThatDoesNotExistIsNull(): void
    {
        $client = new MockHttpClient(new MockResponse('{}', ['http_code' => 404]));

        $this->assertNull(new NgsiLdBroker($client, 'http://broker.example')->entity(self::ID, self::CONTEXT, ['location']));
    }

    public function testItAppendsOnlyTheAttributesThatItGets(): void
    {
        $requests = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [$method, parse_url($url, \PHP_URL_PATH), json_decode((string) $options['body'], true)];

            return new MockResponse('', ['http_code' => 204]);
        });
        $attributes = ['image' => ['type' => 'Property', 'value' => 'p.jpg', 'observedAt' => '2026-11-20T12:00:00Z']];

        $this->assertTrue(new NgsiLdBroker($client, 'http://broker.example')->appendAttributes(self::ID, self::CONTEXT, $attributes));

        $this->assertSame([['POST', '/ngsi-ld/v1/entities/'.rawurlencode(self::ID).'/attrs', $attributes]], $requests);
    }

    public function testItTellsIfTheEntityDoesNotExist(): void
    {
        $client = new MockHttpClient(new MockResponse('{}', ['http_code' => 404]));

        $this->assertFalse(new NgsiLdBroker($client, 'http://broker.example')->appendAttributes(self::ID, self::CONTEXT, []));
    }

    public function testItFailsIfTheBrokerRejectsSomeAttributes(): void
    {
        $client = new MockHttpClient(new MockResponse('{"notUpdated": []}', ['http_code' => 207]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('rejected the update of '.self::ID.' with HTTP 207');

        new NgsiLdBroker($client, 'http://broker.example')->appendAttributes(self::ID, self::CONTEXT, []);
    }
}
