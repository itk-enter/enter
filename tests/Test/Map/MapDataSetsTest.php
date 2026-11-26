<?php

declare(strict_types=1);

namespace App\Tests\Test\Map;

use App\Broker\NgsiLdBroker;
use App\DataSetCatalog;
use App\Test\Map\MapDataSets;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The data of the developer map: only the test data sets that have entities,
 * and the features of one test data set.
 */
class MapDataSetsTest extends KernelTestCase
{
    /**
     * The source ids that the broker was asked about, in the order of the
     * requests.
     *
     * @var list<string>
     */
    private array $asked = [];

    /**
     * Makes the service with a broker that has entities of the toilet data
     * set and its merge only.
     */
    private function dataSets(): MapDataSets
    {
        $client = new MockHttpClient(function (string $method, string $url): MockResponse {
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $sourceId = preg_replace('/^sourceId=="(.*)"$/', '$1', (string) $query['q']);
            $this->asked[] = $sourceId;

            if (isset($query['count'])) {
                $count = \in_array($sourceId, ['test:osm-public-toilet', 'test:merged-public-toilets'], true) ? '3' : '0';

                return new MockResponse('[]', ['response_headers' => ['NGSILD-Results-Count' => $count]]);
            }

            return new MockResponse(json_encode(['type' => 'FeatureCollection', 'features' => [
                ['id' => 'urn:ngsi-ld:PublicToilet:t-1', 'type' => 'Feature', 'geometry' => ['type' => 'Point', 'coordinates' => [10.21, 56.15]], 'properties' => ['name' => 'Toilet 1']],
            ]], \JSON_THROW_ON_ERROR));
        });

        $container = static::getContainer();

        return new MapDataSets(
            $container->get(DataSetCatalog::class),
            new NgsiLdBroker($client, 'http://broker.example'),
            $container->get(UrlGeneratorInterface::class),
        );
    }

    public function testItListsOnlyTheTestDataSetsThatHaveEntities(): void
    {
        $dataSets = $this->dataSets()->all();

        $this->assertSame(['test:osm-public-toilet', 'test:merged-public-toilets'], array_column($dataSets, 'id'));
        $this->assertSame('/test/data-sets/test:osm-public-toilet.geojson', $dataSets[0]['features_url']);
        $this->assertSame('PublicToilet', $dataSets[0]['model']);
        $this->assertSame(['id' => 'test:merged-public-toilets', 'title' => $dataSets[1]['title']], $dataSets[1]['dataset']);

        $this->assertNotEmpty($this->asked);
        foreach ($this->asked as $sourceId) {
            $this->assertStringStartsWith('test:', $sourceId);
        }
    }

    public function testItReadsTheFeaturesOfATestDataSet(): void
    {
        $features = $this->dataSets()->features('test:osm-public-toilet');

        $this->assertNotNull($features);
        $this->assertSame(['urn:ngsi-ld:PublicToilet:t-1'], array_column(iterator_to_array($features, false), 'id'));
        $this->assertSame(['test:osm-public-toilet'], $this->asked);
    }

    public function testADataSetThatIsNotATestDataSetHasNoFeatures(): void
    {
        $dataSets = $this->dataSets();

        $this->assertNull($dataSets->features('osm-public-toilet'));
        $this->assertNull($dataSets->features('test:no-such-data-set'));
        $this->assertSame([], $this->asked);
    }
}
