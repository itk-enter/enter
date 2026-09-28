<?php

declare(strict_types=1);

namespace App\Tests\Test\Map;

use App\Broker\BrokerReader;
use App\Test\Map\SourceFeatures;
use App\Tests\Support\FakeSource;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class SourceFeaturesTest extends TestCase
{
    private const string MINE = 'https://mine.example/feed';

    /**
     * The query the broker was last sent.
     *
     * @var array<string, string>
     */
    private array $query = [];

    /**
     * The headers the broker was last sent, by lower-cased name.
     *
     * @var array<string, string>
     */
    private array $headers = [];

    /**
     * The model is asked for as the source declared it; the context sent
     * along is what lets the broker expand it as it did on publication.
     */
    public function testItAsksForTheModelUnderTheSourcesOwnContext(): void
    {
        $this->read([]);

        $this->assertSame('OnStreetParking', $this->query['type']);
        $this->assertSame(
            '<https://example.com/context.jsonld>; rel="http://www.w3.org/ns/json-ld#context"; type="application/ld+json"',
            $this->headers['link'],
        );
    }

    /**
     * Every source publishes into the same model; the stamp is what keeps
     * one source's entities from another's.
     */
    public function testItAsksOnlyForTheEntitiesCarryingTheSourcesStamp(): void
    {
        $this->read([]);

        $this->assertSame('source=="https://mine.example/feed"', $this->query['q']);
    }

    public function testItAsksForGeoJson(): void
    {
        $this->read([]);

        $this->assertSame('application/geo+json', $this->headers['accept']);
    }

    public function testItSaysWhichDataSetEachFeatureIs(): void
    {
        $features = $this->read([$this->feature('a')]);

        $this->assertSame('mine', $features[0]['properties']['dataset']);
    }

    public function testItCarriesTheEntityIdIntoTheProperties(): void
    {
        $features = $this->read([$this->feature('a'), $this->feature('b')]);

        $this->assertSame(['a', 'b'], array_column(array_column($features, 'properties'), 'id'));
    }

    /**
     * A reader wants values under the names the source declared, not the
     * Property objects NGSI-LD wraps them in.
     */
    public function testItUnwrapsTheValueOfEachAttribute(): void
    {
        $features = $this->read([$this->feature('a')]);

        $this->assertSame(6, $features[0]['properties']['totalSpotNumber']);
    }

    public function testItCarriesTheGeometryThrough(): void
    {
        $features = $this->read([$this->feature('a')]);

        $this->assertSame('Point', $features[0]['geometry']['type']);
    }

    /**
     * The entity type is what was asked for, and the geometry already travels
     * on the feature; repeating either among the properties only adds noise.
     */
    public function testItLeavesOutTheTypeAndTheLocation(): void
    {
        $features = $this->read([$this->feature('a')]);

        $this->assertArrayNotHasKey('type', $features[0]['properties']);
        $this->assertArrayNotHasKey('location', $features[0]['properties']);
    }

    public function testItReturnsAnEmptyCollectionWhenTheSourcePublishedNothing(): void
    {
        $this->assertSame([], $this->read([]));
    }

    /**
     * Reads the source's features from a broker that answers with the given
     * ones, compacted the way a broker handed the context answers.
     *
     * @param list<array<string, mixed>> $features
     *
     * @return list<array<string, mixed>>
     */
    private function read(array $features): array
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($features): MockResponse {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $this->query = $query;
            $this->headers = $this->headersOf($options);

            return new MockResponse(
                json_encode(['type' => 'FeatureCollection', 'features' => $features]),
                ['response_headers' => [
                    'content-type' => ['application/geo+json'],
                    'ngsild-results-count' => [(string) \count($features)],
                ]]
            );
        });

        $collection = new SourceFeatures(new BrokerReader($client, 10000))
            ->forSource(FakeSource::create('Mine', self::MINE));

        return $collection['features'];
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, string>
     */
    private function headersOf(array $options): array
    {
        $headers = [];
        foreach ($options['headers'] ?? [] as $line) {
            [$name, $value] = explode(': ', $line, 2);
            $headers[strtolower($name)] = $value;
        }

        return $headers;
    }

    /**
     * @return array<string, mixed>
     */
    private function feature(string $id, string $geometry = 'Point'): array
    {
        return [
            'id' => $id,
            'type' => 'Feature',
            'geometry' => ['type' => $geometry, 'coordinates' => [10.2, 56.1]],
            'properties' => [
                'type' => 'OnStreetParking',
                'totalSpotNumber' => ['type' => 'Property', 'value' => 6],
                'source' => ['type' => 'Property', 'value' => self::MINE],
                'location' => ['type' => 'GeoProperty', 'value' => ['type' => 'Point', 'coordinates' => [10.2, 56.1]]],
            ],
        ];
    }
}
