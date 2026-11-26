<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the page with the import history of one source.
 */
class SourceHistoryPageTest extends WebTestCase
{
    public function testItShowsTheHistoryOfASource(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/sources/osm-bench');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextSame('h1', 'Bænke (OpenStreetMap), Aarhus Kommune');
        $this->assertSelectorExists('a[href="/sources#osm-bench"]');
    }

    public function testItDoesNotKnowAnUnknownSource(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/sources/no-such-source');

        $this->assertResponseStatusCodeSame(404);
    }
}
