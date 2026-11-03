<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The query parameter "zoom" of the orthophoto route.
 */
class OrthophotoZoomControllerTest extends WebTestCase
{
    public function testTheRouteGivesTheZoomToTheWms(): void
    {
        $browser = static::createClient();
        $urls = [];
        static::getContainer()->set('dataforsyningen.client', new MockHttpClient(static function (string $method, string $url) use (&$urls): MockResponse {
            $urls[] = $url;

            return new MockResponse("\xFF\xD8", ['response_headers' => ['content-type' => 'image/jpeg']]);
        }));

        $browser->request('GET', '/orthophoto/574755,6223760.jpg?zoom=4');

        $this->assertSame(200, $browser->getResponse()->getStatusCode());
        parse_str((string) parse_url($urls[0], \PHP_URL_QUERY), $query);
        $this->assertSame('574735,6223745,574775,6223775', $query['bbox']);
    }

    public function testAnUnknownZoomIsABadRequest(): void
    {
        $browser = static::createClient();

        $browser->request('GET', '/orthophoto/574755,6223760.jpg?zoom=9');

        $this->assertSame(400, $browser->getResponse()->getStatusCode());
    }
}
