<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Broker\NgsiLdBroker;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The data of the developer map: the list of test data sets and the features
 * of one test data set. The map gets all its data from these routes.
 */
class TestControllerMapDataTest extends WebTestCase
{
    /**
     * Makes a client with a broker that has two pages of entities of each
     * data set.
     */
    private static function client(): KernelBrowser
    {
        $client = static::createClient();

        $broker = new MockHttpClient(static function (string $method, string $url): MockResponse {
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

            if (isset($query['count'])) {
                return new MockResponse('[]', ['response_headers' => ['NGSILD-Results-Count' => '2']]);
            }

            $last = isset($query['offset']);
            $feature = ['id' => $last ? 'urn:ngsi-ld:PublicToilet:t-2' : 'urn:ngsi-ld:PublicToilet:t-1', 'type' => 'Feature', 'geometry' => ['type' => 'Point', 'coordinates' => [10.21, 56.15]], 'properties' => ['name' => 'Toilet']];

            return new MockResponse(
                json_encode(['type' => 'FeatureCollection', 'features' => [$feature]], \JSON_THROW_ON_ERROR),
                ['response_headers' => $last ? [] : ['Link' => '<http://scorpio:9090/ngsi-ld/v1/entities?offset=1>; rel="next"']],
            );
        });
        static::getContainer()->set(NgsiLdBroker::class, new NgsiLdBroker($broker, 'http://broker.example'));

        return $client;
    }

    public function testItListsTheTestDataSetsWithTheUrlOfTheirFeatures(): void
    {
        $client = self::client();
        $client->request('GET', '/test/data-sets.json');

        $this->assertResponseIsSuccessful();

        $dataSets = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        $this->assertIsArray($dataSets);
        $this->assertNotEmpty($dataSets);

        foreach ($dataSets as $dataSet) {
            $this->assertStringStartsWith('test:', $dataSet['id']);
            $this->assertSame(\sprintf('/test/data-sets/%s.geojson', $dataSet['id']), $dataSet['features_url']);
        }
    }

    public function testItGivesTheFeaturesOfATestDataSetAsOneCollection(): void
    {
        $client = self::client();
        $client->request('GET', '/test/data-sets/test:osm-public-toilet.geojson');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/geo+json');

        $collection = json_decode((string) $client->getInternalResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        $this->assertIsArray($collection);
        $this->assertSame('FeatureCollection', $collection['type']);
        $this->assertSame(['urn:ngsi-ld:PublicToilet:t-1', 'urn:ngsi-ld:PublicToilet:t-2'], array_column($collection['features'], 'id'));
    }

    public function testADataSetThatIsNotATestDataSetIsNotFound(): void
    {
        $client = self::client();
        $client->request('GET', '/test/data-sets/osm-public-toilet.geojson');

        $this->assertResponseStatusCodeSame(404);
    }
}
