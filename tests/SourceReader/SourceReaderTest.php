<?php

declare(strict_types=1);

namespace App\Tests\SourceReader;

use App\Source\Osm\Bench;
use App\Source\Osm\OnStreetParking;
use App\Source\Osm\ParkingSpot;
use App\SourceReader\SourceReader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sources that split one feed between them are imported one by one, so the
 * reader is what keeps the feed from being fetched once per source.
 */
class SourceReaderTest extends TestCase
{
    /** @var list<string> */
    private array $requested = [];

    public function testSourcesSharingAnAccessUrlShareOneFetch(): void
    {
        $reader = $this->reader([new MockResponse('{"elements": [1]}')]);

        $this->assertSame(['elements' => [1]], $reader->read(new ParkingSpot()));
        $this->assertSame(['elements' => [1]], $reader->read(new OnStreetParking()));
        $this->assertCount(1, $this->requested);
    }

    public function testSourcesWithDifferentAccessUrlsAreFetchedApart(): void
    {
        $reader = $this->reader([new MockResponse('{"elements": [1]}'), new MockResponse('{"elements": [2]}')]);

        $this->assertSame(['elements' => [1]], $reader->read(new ParkingSpot()));
        $this->assertSame(['elements' => [2]], $reader->read(new Bench()));
        $this->assertCount(2, $this->requested);
    }

    public function testAFailedFetchIsNotKept(): void
    {
        $reader = $this->reader([new MockResponse('', ['http_code' => Response::HTTP_TOO_MANY_REQUESTS]), new MockResponse('{"elements": [1]}')]);

        try {
            $reader->read(new ParkingSpot());
            $this->fail('A rate-limited fetch must not read as data.');
        } catch (\Throwable) {
        }

        $this->assertSame(['elements' => [1]], $reader->read(new OnStreetParking()));
        $this->assertCount(2, $this->requested);
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function reader(array $responses): SourceReader
    {
        $client = new MockHttpClient(function (string $method, string $url) use (&$responses): MockResponse {
            $this->requested[] = $url;

            return array_shift($responses) ?? throw new \LogicException('No response left for '.$url);
        });

        return new SourceReader($client, new ArrayAdapter());
    }
}
