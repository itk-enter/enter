<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The published list of sources, as JSON and as a page.
 */
class SourceControllerTest extends WebTestCase
{
    public function testItListsEverySourceAsJson(): void
    {
        $ids = array_column($this->sources(static::createClient()), 'id');

        $this->assertContains('mtm_spatialmaps-handicap-parking', $ids);
        $this->assertContains('osm-handicap-parking-spot', $ids);
        $this->assertContains('osm-handicap-parking-on-street', $ids);
        $this->assertContains('osm-handicap-parking-off-street', $ids);
        $this->assertContains('test:mtm_spatialmaps-handicap-parking', $ids);
    }

    /**
     * A reader needs no more than this to fetch a source's entities from
     * the broker: the URL that selects them by the id they are stamped
     * with, and the context that gives them the names the source declared.
     */
    public function testItSaysHowToReadEachSource(): void
    {
        foreach ($this->sources(static::createClient()) as $source) {
            $this->assertNotSame('', $source['id']);
            $this->assertNotSame('', $source['title']);
            $this->assertNotSame('', $source['model']);
            $this->assertStringStartsWith('https://', $source['context_url']);

            $entitiesUrl = urldecode($source['entities_url']);
            $this->assertStringContainsString('/ngsi-ld/v1/entities?', $entitiesUrl);
            $this->assertStringEndsWith(\sprintf('q=sourceId=="%s"', $source['id']), $entitiesUrl);
        }
    }

    /**
     * Sources that split one feed by model are listed under the data set
     * they share; any other source is a data set of its own.
     */
    public function testItGroupsSourcesByTheirDataSet(): void
    {
        $datasets = array_column($this->sources(static::createClient()), 'dataset', 'id');

        $handicapParking = ['id' => 'osm-handicap-parking', 'title' => 'Handicapparkering (OpenStreetMap), Aarhus Kommune'];
        $this->assertSame($handicapParking, $datasets['osm-handicap-parking-spot']);
        $this->assertSame($handicapParking, $datasets['osm-handicap-parking-on-street']);
        $this->assertSame($handicapParking, $datasets['osm-handicap-parking-off-street']);

        $this->assertSame('osm-bench', $datasets['osm-bench']['id']);
        $this->assertNotSame('', $datasets['osm-bench']['title']);
    }

    public function testItListsEverySourceOnAPage(): void
    {
        $client = static::createClient();
        $ids = array_column($this->sources($client), 'id');

        $client->request('GET', '/sources');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'text/html; charset=UTF-8');
        $this->assertSelectorTextSame('h1', 'Sources');

        $page = (string) $client->getResponse()->getContent();
        foreach ($ids as $id) {
            $this->assertStringContainsString(\sprintf('<code>%s</code>', $id), $page);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sources(KernelBrowser $client): array
    {
        $client->request('GET', '/sources.json');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $sources = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertNotEmpty($sources);

        return $sources;
    }
}
