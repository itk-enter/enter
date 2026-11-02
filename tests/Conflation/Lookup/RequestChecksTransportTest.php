<?php

declare(strict_types=1);

namespace App\Tests\Conflation\Lookup;

use App\Broker\NgsiLdBroker;
use App\Conflation\Conflation;
use App\Conflation\Io\MergedDataSet;
use App\Conflation\Lookup\RemoteLookups;
use App\Ngsi\NgsiEntity;
use App\Tests\Conflation\Fixtures\AerialPhotos;
use App\Tests\Conflation\Fixtures\GroupedByThing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * RemoteLookups::requestChecks() sends each message to the transport that
 * its lookup declares.
 */
class RequestChecksTransportTest extends TestCase
{
    public function testEachMessageHasTheTransportOfItsLookup(): void
    {
        $bus = new class implements MessageBusInterface {
            /** @var list<Envelope> */
            public array $sent = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $envelope = new Envelope($message, $stamps);
                $this->sent[] = $envelope;

                return $envelope;
            }
        };
        $lookups = new RemoteLookups(new NgsiLdBroker(new MockHttpClient(new MockResponse('[]')), 'http://broker.example'), $bus, new MockClock());
        $conflation = new Conflation(
            id: 'merged-toilets',
            title: 'Merged toilets',
            description: 'Toilets merged from several data sets.',
            resultModel: 'PublicToilet',
            defining: ['PublicToilet'],
            mergeByLocation: new GroupedByThing(5.0),
            lookups: [AerialPhotos::class => ['image']],
        );
        $dataSet = new MergedDataSet('merged-toilets', 'Merged toilets', '', 'PublicToilet', 'https://context.example/toilet.jsonld', null, []);
        $entity = new NgsiEntity('urn:ngsi-ld:PublicToilet:merged-toilets-t-1', 'PublicToilet')->geoProperty('location', ['type' => 'Point', 'coordinates' => [10.21, 56.15]]);

        $lookups->requestChecks($dataSet, [$entity], $lookups->keepAnswers($conflation, $dataSet, [$entity]));

        $this->assertCount(1, $bus->sent);
        $this->assertSame(['lookups'], $bus->sent[0]->last(TransportNamesStamp::class)?->getTransportNames());
    }
}
