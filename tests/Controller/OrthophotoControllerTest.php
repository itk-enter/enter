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

    public function testAPointOutsideDenmarkIsNotFound(): void
    {
        $browser = static::createClient();

        $browser->request('GET', '/orthophoto/100000,1000000.jpg');

        $this->assertSame(404, $browser->getResponse()->getStatusCode());
    }

    public function testAnErrorOfTheWmsIsABadGateway(): void
    {
        $browser = static::createClient();
        static::getContainer()->set('dataforsyningen.client', new MockHttpClient(new MockResponse('<ServiceExceptionReport/>', ['response_headers' => ['content-type' => 'text/xml']])));

        $browser->request('GET', '/orthophoto/574755,6223760.jpg');

        $this->assertSame(502, $browser->getResponse()->getStatusCode());
    }
}
