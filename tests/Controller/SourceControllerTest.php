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
        $this->assertContains('osm-handicap-parking', $ids);
        $this->assertContains('test:mtm_spatialmaps-handicap-parking', $ids);
    }

    /**
     * A reader needs no more than this to fetch a source's entities from
     * the broker: the id they are stamped with, the model to ask for, and
     * the context that expands the model's name.
     */
    public function testItSaysHowToReadEachSource(): void
    {
        foreach ($this->sources(static::createClient()) as $source) {
            $this->assertNotSame('', $source['id']);
            $this->assertNotSame('', $source['title']);
            $this->assertNotSame('', $source['model']);
            $this->assertStringStartsWith('https://', $source['context_url']);
        }
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
