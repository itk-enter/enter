<?php

declare(strict_types=1);

namespace App\Tests\Conflation\Lookup;

use App\Conflation\Lookup\LookupHandler;
use App\Conflation\Lookup\LookupMessage;
use App\Tests\Conflation\Fixtures\AerialPhotos;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The Messenger configuration of the lookups.
 */
class LookupMessengerTest extends KernelTestCase
{
    public function testALookupMessageGoesToTheAsyncTransport(): void
    {
        $container = self::getContainer();
        $message = new LookupMessage('urn:ngsi-ld:PublicToilet:t-1', 'https://context.example/toilet.jsonld', AerialPhotos::class, ['image'], ['type' => 'Point', 'coordinates' => [10.21, 56.15]], null);

        $container->get(MessageBusInterface::class)->dispatch($message);

        $transport = $container->get('messenger.transport.async');
        $this->assertInstanceOf(InMemoryTransport::class, $transport);
        $this->assertCount(1, $transport->getSent());
        $this->assertSame($message, $transport->getSent()[0]->getMessage());
    }

    public function testTheContainerBuildsTheHandler(): void
    {
        $this->assertInstanceOf(LookupHandler::class, self::getContainer()->get(LookupHandler::class));
    }
}
