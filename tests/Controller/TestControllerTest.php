<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The developer map page carries no data of its own: it points its
 * controllers at the sources list and the broker proxy, and everything
 * drawn is fetched from there.
 */
class TestControllerTest extends WebTestCase
{
    public function testItServesTheMapPageWithWhereToFetchFrom(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/test');

        $this->assertResponseIsSuccessful();

        $wrapper = $crawler->filter('[data-controller~="test-datasets"]');

        $this->assertStringContainsString('test-map', (string) $wrapper->attr('data-controller'));
        $this->assertSame('/sources.json', $wrapper->attr('data-test-datasets-url-value'));
        $this->assertSame('/data/ngsi-ld/v1/entities.geojson', $wrapper->attr('data-test-map-entities-url-value'));
        $this->assertCount(1, $crawler->filter('[data-test-map-target="canvas"]'));
    }
}
