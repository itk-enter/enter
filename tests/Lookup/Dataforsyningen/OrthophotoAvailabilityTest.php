<?php

declare(strict_types=1);

namespace App\Tests\Lookup\Dataforsyningen;

use App\GeometryHelper;
use App\Lookup\Dataforsyningen\Orthophoto;
use App\Lookup\Dataforsyningen\OrthophotoClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;

/**
 * Orthophoto::checkAvailability() fails if the WMS gives no image.
 */
class OrthophotoAvailabilityTest extends TestCase
{
    public function testNoImageFromTheWmsMakesTheCheckFail(): void
    {
        $wms = new MockHttpClient(new MockResponse('<ServiceExceptionReport/>', ['response_headers' => ['content-type' => 'text/xml']]));
        $lookup = new Orthophoto(new OrthophotoClient($wms), new GeometryHelper(), new UrlGenerator(new RouteCollection(), new RequestContext()));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('gave no image');

        $lookup->checkAvailability(10.2034, 56.1530);
    }
}
