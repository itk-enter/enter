<?php

declare(strict_types=1);

namespace App\Tests\Test\Fixture;

use App\Source\Osm\Bench;
use App\Test\Fixture\FixtureFetcher;
use App\Test\Source\MtmSpatialMaps\TestOnStreetParking;
use App\Test\Source\MtmSpatialMaps\TestParkingSpot;
use App\Test\Source\Osm\TestBench;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refreshing fixtures from public feeds fails now and then, so a failure
 * must cost neither the old fixture nor what already succeeded.
 */
class FixtureFetcherTest extends TestCase
{
    private string $directory;

    /** @var list<string> */
    private array $requested = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/fixture-fetcher-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    public function testItWritesTheFeedToTheFixtureTheSourceReads(): void
    {
        $fetch = $this->fetcher([new MockResponse('{"features": []}')])->fetch(new TestParkingSpot());

        $this->assertSame($this->directory.'/data/webkort.aarhuskommune.dk/spatialmap?mtm_spatialmaps-handicap-parking', $fetch->filename);
        $this->assertSame('{"features": []}', file_get_contents($fetch->filename));
        $this->assertFalse($fetch->fromCache);
    }

    public function testSourcesSplittingOneFeedFetchItOnce(): void
    {
        $fetcher = $this->fetcher([new MockResponse('{"features": []}')]);

        $fetcher->fetch(new TestParkingSpot());
        $fetch = $fetcher->fetch(new TestOnStreetParking());

        $this->assertCount(1, $this->requested);
        $this->assertTrue($fetch->fromCache);
    }

    public function testItFetchesAKeptFeedAgainWhenAskedForAFreshOne(): void
    {
        $fetcher = $this->fetcher([new MockResponse('{"features": [1]}'), new MockResponse('{"features": [2]}')]);

        $fetcher->fetch(new TestParkingSpot());
        $fetch = $fetcher->fetch(new TestParkingSpot(), fresh: true);

        $this->assertCount(2, $this->requested);
        $this->assertSame('{"features": [2]}', file_get_contents($fetch->filename));
    }

    public function testAFailedFetchKeepsTheOldFixture(): void
    {
        $fetcher = $this->fetcher([new MockResponse('{"elements": [1]}'), new MockResponse('', ['http_code' => Response::HTTP_GATEWAY_TIMEOUT])]);
        $fetch = $fetcher->fetch(new TestBench());

        try {
            $fetcher->fetch(new TestBench(), fresh: true);
            $this->fail('A failed fetch passed.');
        } catch (\Exception) {
        }

        $this->assertSame('{"elements": [1]}', file_get_contents($fetch->filename));
    }

    /**
     * A failure is not kept, so the next run tries the feed again.
     */
    public function testAFailedFetchIsTriedAgainOnTheNextRun(): void
    {
        $fetcher = $this->fetcher([new MockResponse('', ['http_code' => Response::HTTP_TOO_MANY_REQUESTS]), new MockResponse('{"elements": []}')]);

        try {
            $fetcher->fetch(new TestBench());
            $this->fail('A failed fetch passed.');
        } catch (\Exception) {
        }
        $fetch = $fetcher->fetch(new TestBench());

        $this->assertCount(2, $this->requested);
        $this->assertFalse($fetch->fromCache);
    }

    public function testItRefusesAPartialOverpassResult(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('timed out');

        $this->fetcher([new MockResponse('{"elements": [], "remark": "runtime error: Query timed out"}')])->fetch(new TestBench());
    }

    public function testItRefusesASourceThatIsNotATestSource(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->fetcher([])->fetch(new Bench());
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function fetcher(array $responses): FixtureFetcher
    {
        $client = new MockHttpClient(function (string $method, string $url) use (&$responses): MockResponse {
            $this->requested[] = $url;

            return array_shift($responses) ?? throw new \LogicException('No response left.');
        });

        return new FixtureFetcher($client, new ArrayAdapter(), new Filesystem(), $this->directory);
    }
}
