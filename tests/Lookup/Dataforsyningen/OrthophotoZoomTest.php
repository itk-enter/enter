<?php

declare(strict_types=1);

namespace App\Tests\Lookup\Dataforsyningen;

use App\Lookup\Dataforsyningen\OrthophotoClient;
use App\Lookup\Dataforsyningen\OrthophotoZoom;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class OrthophotoZoomTest extends TestCase
{
    #[DataProvider('levels')]
    public function testEachLevelAsksForItsArea(OrthophotoZoom $zoom, string $bbox): void
    {
        $query = [];
        $client = new MockHttpClient(static function (string $method, string $url) use (&$query): MockResponse {
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

            return new MockResponse("\xFF\xD8", ['response_headers' => ['content-type' => 'image/jpeg']]);
        });

        new OrthophotoClient($client)->image(574755, 6223760, $zoom);

        $this->assertSame($bbox, $query['bbox']);
        $this->assertSame(['400', '300'], [$query['width'], $query['height']]);
    }

    /**
     * @return iterable<string, array{OrthophotoZoom, string}>
     */
    public static function levels(): iterable
    {
        yield '1: 320 × 240 m' => [OrthophotoZoom::Wide, '574595,6223640,574915,6223880'];
        yield '2: 160 × 120 m' => [OrthophotoZoom::Medium, '574675,6223700,574835,6223820'];
        yield '3: 80 × 60 m' => [OrthophotoZoom::Default, '574715,6223730,574795,6223790'];
        yield '4: 40 × 30 m' => [OrthophotoZoom::Close, '574735,6223745,574775,6223775'];
    }
}
