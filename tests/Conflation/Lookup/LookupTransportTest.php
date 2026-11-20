<?php

declare(strict_types=1);

namespace App\Tests\Conflation\Lookup;

use App\Conflation\Lookup\AsLocationLookup;
use App\Conflation\Lookup\LookupMessage;
use App\Tests\Conflation\Fixtures\NearestAddress;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The transport of each lookup: its queue, its request limit and its
 * retries.
 */
class LookupTransportTest extends KernelTestCase
{
    public function testALookupWithoutATransportOfItsOwnUsesTheLookupsTransport(): void
    {
        $this->assertSame(AsLocationLookup::DEFAULT_TRANSPORT, AsLocationLookup::of(NearestAddress::class)->transport);
    }

    public function testTheStampSendsTheMessageToTheTransportOfTheLookup(): void
    {
        $container = self::getContainer();
        $message = new LookupMessage('urn:ngsi-ld:PublicToilet:t-1', 'https://context.example/toilet.jsonld', NearestAddress::class, ['address'], ['type' => 'Point', 'coordinates' => [10.21, 56.15]], null);

        $container->get(MessageBusInterface::class)->dispatch($message, [new TransportNamesStamp(['lookups'])]);

        $lookups = $container->get('messenger.transport.lookups');
        $async = $container->get('messenger.transport.async');
        $this->assertInstanceOf(InMemoryTransport::class, $lookups);
        $this->assertInstanceOf(InMemoryTransport::class, $async);
        $this->assertCount(1, $lookups->getSent());
        $this->assertCount(0, $async->getSent());
    }

    public function testTheLookupsTransportHasARateLimiter(): void
    {
        $this->assertTrue(self::getContainer()->has('limiter.lookups'));
    }
}
