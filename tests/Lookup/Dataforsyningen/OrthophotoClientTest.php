<?php

declare(strict_types=1);

namespace App\Tests\Lookup\Dataforsyningen;

use App\Lookup\Dataforsyningen\OrthophotoClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class OrthophotoClientTest extends TestCase
{
    public function testItAsksTheWmsForAnImageOfTheAreaAroundThePoint(): void
    {
        $query = [];
        $client = new MockHttpClient(static function (string $method, string $url) use (&$query): MockResponse {
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

            return new MockResponse("\xFF\xD8jpeg", ['response_headers' => ['content-type' => 'image/jpeg']]);
        });

        $this->assertSame("\xFF\xD8jpeg", new OrthophotoClient($client)->image(574755, 6223760));

        $this->assertSame('GetMap', $query['request']);
        $this->assertSame('orto_foraar', $query['layers']);
        $this->assertSame('EPSG:25832', $query['crs']);
        // 400 × 300 pixels of 0.2 m: 80 × 60 m around the point.
        $this->assertSame('574715,6223730,574795,6223790', $query['bbox']);
        $this->assertSame(['400', '300'], [$query['width'], $query['height']]);
    }

    public function testAnErrorOfTheWmsIsAnException(): void
    {
        $client = new MockHttpClient(new MockResponse('<ServiceExceptionReport>Layer not found</ServiceExceptionReport>', ['response_headers' => ['content-type' => 'text/xml']]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Layer not found');

        new OrthophotoClient($client)->image(574755, 6223760);
    }

    public function testItKnowsTheExtentOfDenmark(): void
    {
        $this->assertTrue(OrthophotoClient::contains(574755, 6223760));
        $this->assertTrue(OrthophotoClient::contains(870000, 6120000), 'Bornholm');
        $this->assertFalse(OrthophotoClient::contains(100000, 1000000));
    }
}
