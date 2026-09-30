<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The developer map page carries no data of its own: it points its
 * controllers at the sources list, and everything drawn is fetched from
 * where that list says.
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
        $this->assertCount(1, $crawler->filter('[data-test-map-target="canvas"]'));
    }
}
