<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Broker\NgsiLdBroker;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the page of a merged data set with its conflicts.
 *
 * The broker is a mock. It answers each read with the given features.
 */
class MergedDataSetPageTest extends WebTestCase
{
    private const string MERGE = 'test:merged-public-toilets';

    /**
     * The query of each read of the broker.
     *
     * @var list<string>
     */
    private array $queries = [];

    public function testItShowsTheConflictsOfAMergedDataSet(): void
    {
        $client = $this->client(new MockResponse(json_encode(['type' => 'FeatureCollection', 'features' => [
            self::feature('urn:ngsi-ld:PublicToilet:merged-2', 'Parktoilet', ['isAccessibleForFree']),
            self::feature('urn:ngsi-ld:PublicToilet:merged-1', 'Toilet ved Hotel Marselis', ['isAccessibleForFree', 'handwashing']),
        ]], \JSON_THROW_ON_ERROR)));

        $client->request(Request::METHOD_GET, '/sources/'.self::MERGE);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextSame('h1', 'Test: Offentlige toiletter (samlet), Aarhus Kommune');
        $this->assertSelectorTextContains('.conflict-summary', '2 samlede entiteter har en konflikt');
        $this->assertSelectorCount(2, '.conflict-entity');
        $this->assertSelectorTextSame('#conflict-0 .fold-card-title', 'Toilet ved Hotel Marselis');
        $this->assertSelectorTextContains('#conflict-0 .conflict-values', 'Test: Offentlige toiletter (FindToilet), Aarhus Kommune');
        $this->assertSelectorExists('[data-controller="conflict-map"]');
        $this->assertStringContainsString('sourceId=="'.self::MERGE.'";additionalInformation[conflicts]', $this->queries[0]);
    }

    public function testItSaysThatAMergeWithoutConflictsHasNone(): void
    {
        $client = $this->client(new MockResponse('{"type": "FeatureCollection", "features": []}'));

        $client->request(Request::METHOD_GET, '/sources/'.self::MERGE);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.conflict-summary', 'Ingen konflikter');
        $this->assertSelectorNotExists('[data-controller="conflict-map"]');
    }

    public function testItSaysWhenTheBrokerIsNotAvailable(): void
    {
        $client = $this->client(new MockResponse('', ['http_code' => 503]));

        $client->request(Request::METHOD_GET, '/sources/'.self::MERGE);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Brokeren er ikke tilgængelig');
    }

    public function testTheCardOfAMergedDataSetLinksToItsPage(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/sources');

        $this->assertSelectorExists(\sprintf('#%s a[href="/sources/%s"]', 'test\:merged-public-toilets', self::MERGE));
    }

    /**
     * Makes a client with a broker that gives the response to each read.
     */
    private function client(MockResponse $response): KernelBrowser
    {
        $client = static::createClient();
        $broker = new MockHttpClient(function (string $method, string $url) use ($response): MockResponse {
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $this->queries[] = (string) ($query['q'] ?? '');

            return $response;
        });
        static::getContainer()->set(NgsiLdBroker::class, new NgsiLdBroker($broker, 'http://broker.example'));

        return $client;
    }

    /**
     * @param list<string> $attributes the attributes that the FindToilet and OSM data sets disagree on
     *
     * @return array<string, mixed>
     */
    private static function feature(string $id, string $name, array $attributes): array
    {
        $conflicts = [];
        foreach ($attributes as $attribute) {
            $conflicts[$attribute] = [
                ['value' => true, 'dataSet' => 'test:findtoilet-public-toilet', 'entity' => 'urn:ngsi-ld:PublicToilet:test:findtoilet-1'],
                ['value' => false, 'dataSet' => 'test:osm-public-toilet', 'entity' => 'urn:ngsi-ld:PublicToilet:test:osm-node-1'],
            ];
        }

        return [
            'type' => 'Feature',
            'id' => $id,
            'geometry' => ['type' => 'Point', 'coordinates' => [10.21, 56.15]],
            'properties' => ['type' => 'PublicToilet', 'name' => $name, 'additionalInformation' => ['conflicts' => $conflicts]],
        ];
    }
}
