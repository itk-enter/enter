<?php

declare(strict_types=1);

namespace App\Tests\Skraafoto;

use App\Skraafoto\SkraafotoClient;
use App\Skraafoto\SkraafotoNotFoundException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Tests the requests to the Skråfoto API, with responses that the test gives.
 */
class SkraafotoClientTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $urls = [];

    public function testItTakesTheCollectionOfTheMostRecentYear(): void
    {
        $client = $this->client([new JsonMockResponse(['collections' => [
            ['id' => 'skraafotos2021'],
            ['id' => 'skraafotos2023'],
            ['id' => 'other'],
            ['id' => 'skraafotos2019'],
        ]])]);

        $this->assertSame('skraafotos2023', $client->newestCollection());
    }

    public function testItTakesTheNewestObliquePhoto(): void
    {
        $client = $this->client([new JsonMockResponse(['features' => [
            self::photo('nadir', 'nadir', 10.21, 56.15),
            self::photo('newest', 'east', 10.21, 56.15),
            self::photo('older', 'north', 10.21, 56.15),
        ]])]);

        $this->assertSame('newest', $client->photoAt('skraafotos2023', 10.21, 56.15)['id'] ?? null);
        $this->assertStringContainsString('sortby=-properties.datetime', $this->urls[0]);
    }

    public function testItGivesNoPhotoIfNoObliquePhotoShowsThePoint(): void
    {
        $client = $this->client([new JsonMockResponse(['features' => [self::photo('nadir', 'nadir', 10.21, 56.15)]])]);

        $this->assertNull($client->photoAt('skraafotos2023', 10.21, 56.15));
    }

    public function testItReadsTheThumbnailFromTheCogtiler(): void
    {
        $href = SkraafotoClient::COGTILER_URL.'/thumbnail.jpg?url=https%3A%2F%2Fcdn.dataforsyningen.dk%2Fphoto.tif';
        $client = $this->client([
            new JsonMockResponse(['id' => 'near', 'assets' => ['thumbnail' => ['href' => $href]]]),
            new MockResponse('JPEG'),
        ]);

        $this->assertSame('JPEG', $client->thumbnail('skraafotos2023', 'near'));
        $this->assertSame($href, $this->urls[1]);
    }

    public function testItRefusesAThumbnailOnAnotherHost(): void
    {
        $client = $this->client([
            new JsonMockResponse(['id' => 'near', 'assets' => ['thumbnail' => ['href' => 'https://example.com/thumbnail.jpg']]]),
        ]);

        $this->expectException(SkraafotoNotFoundException::class);

        $client->thumbnail('skraafotos2023', 'near');
    }

    public function testItTellsTheUserToSetTheTokenIfTheApiRefusesIt(): void
    {
        $client = $this->client([new MockResponse('Unauthorized', ['http_code' => 401])]);

        $this->expectExceptionMessage('DATAFORSYNINGEN_TOKEN');

        $client->newestCollection();
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function client(array $responses): SkraafotoClient
    {
        $mock = new MockHttpClient(function (string $method, string $url) use (&$responses): MockResponse {
            $this->urls[] = $url;

            return array_shift($responses) ?? throw new \LogicException('No more responses.');
        });

        return new SkraafotoClient($mock, new ArrayAdapter());
    }

    /**
     * A photo with a square footprint of about 20 metres around a center.
     *
     * @return array<string, mixed>
     */
    private static function photo(string $id, string $direction, float $longitude, float $latitude): array
    {
        $d = 0.0001;

        return [
            'id' => $id,
            'properties' => ['direction' => $direction],
            'geometry' => ['type' => 'Polygon', 'coordinates' => [[
                [$longitude - $d, $latitude - $d],
                [$longitude + $d, $latitude - $d],
                [$longitude + $d, $latitude + $d],
                [$longitude - $d, $latitude + $d],
                [$longitude - $d, $latitude - $d],
            ]]],
        ];
    }
}
