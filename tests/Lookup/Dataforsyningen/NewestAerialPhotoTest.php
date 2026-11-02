<?php

declare(strict_types=1);

namespace App\Tests\Lookup\Dataforsyningen;

use App\Lookup\Dataforsyningen\NewestAerialPhoto;
use App\Lookup\Dataforsyningen\SkraafotoClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

class NewestAerialPhotoTest extends TestCase
{
    /**
     * A lookup over a Skråfoto API that has the collection skraafotos2023,
     * with the given photos at each point.
     *
     * @param list<array<string, mixed>> $photos
     */
    private static function lookup(array $photos): NewestAerialPhoto
    {
        $client = new MockHttpClient([
            new JsonMockResponse(['collections' => [['id' => 'skraafotos2023']]]),
            new JsonMockResponse(['features' => $photos]),
        ]);
        $routes = new RouteCollection();
        $routes->add('app_skraafoto_thumbnail', new Route('/skraafoto/{collection}/{photo}.jpg'));
        $urls = new UrlGenerator($routes, new RequestContext(host: 'enter.example', scheme: 'https'));

        return new NewestAerialPhoto(new SkraafotoClient($client, new ArrayAdapter()), $urls);
    }

    public function testItGivesTheThumbnailRouteAndTheDateOfTheNewestPhoto(): void
    {
        $answer = self::lookup([
            ['id' => '2023_83_29_1_0031_00003478', 'properties' => ['direction' => 'north', 'datetime' => '2023-04-20T10:15:00Z']],
        ])->lookup(10.21, 56.15);

        $this->assertSame([
            'image' => 'https://enter.example/skraafoto/skraafotos2023/2023_83_29_1_0031_00003478.jpg',
            'imageDate' => '2023-04-20',
        ], $answer);
    }

    public function testItHasNoAnswerIfNoObliquePhotoShowsThePoint(): void
    {
        $this->assertNull(self::lookup([['id' => 'nadir', 'properties' => ['direction' => 'nadir']]])->lookup(10.21, 56.15));
    }
}
