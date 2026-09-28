<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The developer map's feed: which data sets there are, and where each is
 * fetched from.
 */
class TestControllerTest extends WebTestCase
{
    public function testItListsEveryTestSourceAsADataSet(): void
    {
        $datasets = $this->datasets();

        $this->assertContains('test:osm-handicap-parking', array_column($datasets, 'id'));
        $this->assertContains('test:mtm_spatialmaps-handicap-parking', array_column($datasets, 'id'));
    }

    public function testItListsTestSourcesOnly(): void
    {
        foreach (array_column($this->datasets(), 'id') as $id) {
            $this->assertStringStartsWith('test:', $id);
        }
    }

    /**
     * The map asks nothing of a data set beyond what is listed here: a label
     * to show, a model to group by, and a URL to fetch.
     */
    public function testItSaysWhatEachDataSetIsAndWhereToFetchIt(): void
    {
        foreach ($this->datasets() as $dataset) {
            $this->assertSame(['id', 'title', 'model', 'url'], array_keys($dataset));
            $this->assertNotSame('', $dataset['title']);
            $this->assertNotSame('', $dataset['model']);
            $this->assertStringStartsWith('/test/map/'.$dataset['id'], $dataset['url']);
        }
    }

    public function testItServesNoDataSetForASourceThatIsNotATestSource(): void
    {
        $client = static::createClient();
        $client->request('GET', '/test/map/mtm_spatialmaps-handicap-parking');

        $this->assertResponseStatusCodeSame(404);
    }

    /**
     * @return list<array<string, string>>
     */
    private function datasets(): array
    {
        $client = static::createClient();
        $client->request('GET', '/test/datasets.json');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');

        return json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
