<?php

declare(strict_types=1);

namespace App\Tests\Lookup\Dataforsyningen;

use App\Conflation\Lookup\AsLocationLookup;
use App\GeometryHelper;
use App\Lookup\Dataforsyningen\Orthophoto;
use App\Lookup\Dataforsyningen\OrthophotoClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

class OrthophotoTest extends TestCase
{
    /**
     * The URLs that the WMS received.
     *
     * @var list<string>
     */
    private array $requests = [];

    private function lookup(): Orthophoto
    {
        $routes = new RouteCollection();
        $routes->add('app_orthophoto', new Route('/orthophoto/{x},{y}.jpg'));
        $wms = new MockHttpClient(function (string $method, string $url): MockResponse {
            $this->requests[] = $url;

            return new MockResponse("\xFF\xD8", ['response_headers' => ['content-type' => 'image/jpeg']]);
        });

        return new Orthophoto(new OrthophotoClient($wms), new GeometryHelper(), new UrlGenerator($routes, new RequestContext(host: 'enter.example', scheme: 'https')));
    }

    public function testItGivesTheRouteOfThePointInUtm32WithoutARequest(): void
    {
        // The city hall of Aarhus.
        $this->assertSame(['image' => 'https://enter.example/orthophoto/574755,6223760.jpg'], $this->lookup()->lookup(10.2034, 56.1530));
        $this->assertSame([], $this->requests);
    }

    public function testAPointThatTheRouteDoesNotAcceptGetsNoImage(): void
    {
        // Paris is far west of UTM zone 32, so its easting has 5 digits.
        $this->assertNull($this->lookup()->lookup(2.35, 48.85));
    }

    public function testTheServiceCheckAsksTheWms(): void
    {
        $this->lookup()->checkAvailability(10.2034, 56.1530);

        $this->assertCount(1, $this->requests);
        $this->assertStringStartsWith(OrthophotoClient::WMS_URL, $this->requests[0]);
    }

    public function testItHasNoTransport(): void
    {
        $this->assertTrue(AsLocationLookup::of(Orthophoto::class)->isInline());
    }
}
