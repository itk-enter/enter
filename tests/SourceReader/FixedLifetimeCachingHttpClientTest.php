<?php

declare(strict_types=1);

namespace App\Tests\SourceReader;

use App\SourceReader\FixedLifetimeCachingHttpClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the cache key of the caching client.
 */
class FixedLifetimeCachingHttpClientTest extends TestCase
{
    private int $requests = 0;

    public function testRequestsWithTheSameUrlAndOptionsShareOneFetch(): void
    {
        $client = $this->client();

        $client->request(Request::METHOD_GET, 'https://example.com/feed', ['query' => ['a' => 1]])->getContent();
        $client->request(Request::METHOD_GET, 'https://example.com/feed', ['query' => ['a' => 1]])->getContent();

        $this->assertSame(1, $this->requests);
    }

    public function testRequestsWithOtherOptionsAreFetchedApart(): void
    {
        $client = $this->client();

        $client->request(Request::METHOD_GET, 'https://example.com/feed', ['headers' => ['Accept' => 'application/json']])->getContent();
        $client->request(Request::METHOD_GET, 'https://example.com/feed', ['headers' => ['Accept' => 'application/geo+json']])->getContent();

        $this->assertSame(2, $this->requests);
    }

    public function testItRefusesOptionsThatJsonCannotEncode(): void
    {
        $this->expectException(\JsonException::class);

        $this->client()->request(Request::METHOD_GET, 'https://example.com/feed', ['user_data' => \NAN]);
    }

    private function client(): FixedLifetimeCachingHttpClient
    {
        $client = new MockHttpClient(function (): MockResponse {
            ++$this->requests;

            return new MockResponse('{}');
        });

        return new FixedLifetimeCachingHttpClient($client, new ArrayAdapter());
    }
}
