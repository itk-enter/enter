<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Tests the front page.
 */
class DefaultControllerTest extends WebTestCase
{
    public function testItServesTheFrontPage(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('a[href*="/ngsi-ld/v1/"]');
    }
}
