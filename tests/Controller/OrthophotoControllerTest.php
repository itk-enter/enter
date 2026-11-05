<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class OrthophotoControllerTest extends WebTestCase
{
    public function testItSendsTheImageOfTheWmsWithACacheTime(): void
    {
        $browser = static::createClient();
        static::getContainer()->set('dataforsyningen.client', new MockHttpClient(new MockResponse("\xFF\xD8jpeg", ['response_headers' => ['content-type' => 'image/jpeg']])));

        $browser->request('GET', '/orthophoto/574755,6223760.jpg');

        $response = $browser->getResponse();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertSame("\xFF\xD8jpeg", $response->getContent());
        $this->assertTrue($response->headers->hasCacheControlDirective('public'));
        $this->assertSame('604800', $response->headers->getCacheControlDirective('max-age'));
    }

    public function testACoordinateWithAnotherFormatDoesNotReachTheWms(): void
    {
        $browser = static::createClient();
        $urls = [];
        static::getContainer()->set('dataforsyningen.client', new MockHttpClient(static function (string $method, string $url) use (&$urls): MockResponse {
            $urls[] = $url;

            return new MockResponse("\xFF\xD8", ['response_headers' => ['content-type' => 'image/jpeg']]);
        }));

        // The easting has 5 digits, so the orthophoto route does not match.
        $browser->request('GET', '/orthophoto/12345,6223760.jpg');

        $this->assertSame([], $urls);
        $this->assertNotSame('image/jpeg', $browser->getResponse()->headers->get('Content-Type'));
    }

    public function testNoImageFromTheWmsIsNotFound(): void
    {
        $browser = static::createClient();
        static::getContainer()->set('dataforsyningen.client', new MockHttpClient(new MockResponse('<ServiceExceptionReport/>', ['response_headers' => ['content-type' => 'text/xml']])));

        $browser->request('GET', '/orthophoto/574755,6223760.jpg');

        $this->assertSame(404, $browser->getResponse()->getStatusCode());
    }
}
